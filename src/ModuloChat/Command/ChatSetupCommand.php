<?php

namespace App\ModuloChat\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'modulochat:setup',
    description: 'Configurar el módulo de chat: configurar la base de datos y generar migraciones'
)]
class ChatSetupCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp(<<<EOT
            El comando <info>modulochat:setup</info> realiza los siguientes pasos automáticamente:

            1. Actualiza la configuración de servicios.yaml para incluir el módulo de chat.
            2. Actualiza la configuración de routes.yaml para incluir las rutas del módulo de chat.
            3. Actualiza la configuración de twig.yaml para incluir las plantillas del módulo de chat.
            4. Actualiza la configuración de doctrine.yaml para incluir las entidades del módulo de chat.
            5. Genera una nueva migración para incluir las entidades del chat.
            6. Ejecuta la migración para aplicar los cambios en la base de datos.

            Ejemplo de uso:

            <info>php bin/console modulochat:setup</info>
            EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // 1. Actualizar services.yaml
        $this->updateServicesYaml($io);
        
        // 2. Actualizar routes.yaml
        $this->updateRoutesYaml($io);
        
        // 3. Actualizar twig.yaml
        $this->updateTwigYaml($io);
        
        // 4. Actualizar doctrine.yaml
        $this->updateDoctrineYaml($io);
        
        // Antes de generar la migración, preguntar al usuario
        if (!$io->confirm('¿Deseas generar y ejecutar la migración de la base de datos ahora?', true)) {
            $io->note('Operaciones de base de datos omitidas. Puedes ejecutarlas manualmente más tarde.');
            $io->success('Configuración de archivos completada.');
            return Command::SUCCESS;
        }
        
        // 5. Generar migración
        $io->section('Generando migración...');
        $process = new Process(['php', 'bin/console', 'make:migration']);
        $process->setTimeout(120); // 2 minutos de timeout
        $process->run(function ($type, $buffer) use ($io) {
            $io->write($buffer);
        });
        
        if (!$process->isSuccessful()) {
            $io->error('Error al generar la migración. Puedes intentarlo manualmente más tarde.');
            return Command::FAILURE;
        }
        
        $io->success('Migración generada para las entidades del chat.');
        
        // Confirmar antes de ejecutar la migración
        if (!$io->confirm('¿Deseas ejecutar la migración ahora?', true)) {
            $io->note('Migración no ejecutada. Puedes ejecutarla manualmente más tarde.');
            return Command::SUCCESS;
        }
        
        // 6. Ejecutar migración
        $io->section('Ejecutando migración...');
        $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
        $process->setTimeout(300); // 5 minutos de timeout
        $process->run(function ($type, $buffer) use ($io) {
            $io->write($buffer);
        });
        
        if (!$process->isSuccessful()) {
            $io->error('Error al ejecutar la migración. Puedes intentarlo manualmente más tarde.');
            return Command::FAILURE;
        }
        
        $io->success('Migración ejecutada. Las tablas del chat han sido creadas en la base de datos.');
        $io->success('¡Módulo de chat configurado exitosamente!');
        $io->note('Puedes acceder al chat en la ruta /chat');
        
        return Command::SUCCESS;
    }
    
    private function updateServicesYaml(SymfonyStyle $io): void
    {
        $servicesYamlPath = 'config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);
        
        // Verificar si la configuración ya existe
        if (strpos($servicesContent, 'App\ModuloChat\Controller') !== false) {
            $io->note('La configuración de servicios ya incluye el módulo de chat.');
            return;
        }
        
        // Buscar dónde insertar la configuración
        $pattern = '#END\s+------+\s+ModuloCore\s+------+';
        
        if (!preg_match('/' . $pattern . '/', $servicesContent)) {
            $io->warning('No se pudo encontrar el punto de inserción en services.yaml.');
            if (!$io->confirm('¿Deseas añadir la configuración del chat al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            // Añadir al final del archivo si no se encuentra el punto de inserción
            $servicesContent .= "\n\n" . $this->getChatServicesConfig();
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('La configuración del chat se ha añadido al final de services.yaml.');
            return;
        }
        
        // Si encontramos el punto de inserción, realizar reemplazo seguro
        $chatConfig = $this->getChatServicesConfig();
        
        // Realizar reemplazo con preg_replace para mayor seguridad
        $newContent = preg_replace('/' . $pattern . '/', "$0" . $chatConfig, $servicesContent, 1);
        
        // Verificar que el reemplazo fue exitoso
        if ($newContent !== $servicesContent) {
            file_put_contents($servicesYamlPath, $newContent);
            $io->success('services.yaml actualizado con la configuración del módulo de chat.');
        } else {
            $io->error('No se pudo actualizar services.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getChatServicesConfig(): string
    {
        return <<<EOT
        
    #START -----------------------------------------------------  ModuloChat -------------------------------------------------------------------------- 
    App\ModuloChat\Controller\:
        resource: '../src/ModuloChat/Controller'
        tags: ['controller.service_arguments']
        
    App\ModuloChat\Service\:
        resource: '../src/ModuloChat/Service/'
        autowire: true
        autoconfigure: true
        public: true
    #END ------------------------------------------------------- ModuloChat -----------------------------------------------------------------------------
EOT;
    }
    
    private function updateRoutesYaml(SymfonyStyle $io): void
    {
        $routesYamlPath = 'config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        // Verificar si la configuración ya existe
        if (strpos($routesContent, 'App\ModuloChat\Controller') !== false) {
            $io->note('La configuración de rutas ya incluye el módulo de chat.');
            return;
        }
        
        // Buscar un punto de inserción seguro
        $patterns = [
            'modulo_Explorador_controllers:',
            'modulo_core_controllers:',
            '# modulo_test_controllers:'
        ];
        
        $insertPoint = null;
        foreach ($patterns as $pattern) {
            if (strpos($routesContent, $pattern) !== false) {
                $insertPoint = $pattern;
                break;
            }
        }
        
        if ($insertPoint === null) {
            $io->warning('No se pudo encontrar un punto de inserción seguro en routes.yaml.');
            if (!$io->confirm('¿Deseas añadir la configuración del chat al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            // Añadir al final del archivo si no se encuentra el punto de inserción
            $routesContent .= "\n\n" . $this->getChatRoutesConfig();
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('La configuración del chat se ha añadido al final de routes.yaml.');
            return;
        }
        
        $chatConfig = $this->getChatRoutesConfig();
        
        // Asegurar que insertamos después del elemento completo
        $pattern = '/(' . preg_quote($insertPoint) . '.*?type:\s+attribute)/s';
        if (preg_match($pattern, $routesContent, $matches)) {
            $newContent = str_replace($matches[1], $matches[1] . $chatConfig, $routesContent);
            file_put_contents($routesYamlPath, $newContent);
            $io->success('routes.yaml actualizado con las rutas del módulo de chat.');
        } else {
            $io->error('No se pudo actualizar routes.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getChatRoutesConfig(): string
    {
        return <<<EOT

modulo_chat_controllers:
    resource:
        path: ../src/ModuloChat/Controller/
        namespace: App\ModuloChat\Controller
    type: attribute
EOT;
    }
    
    private function updateTwigYaml(SymfonyStyle $io): void
    {
        $twigYamlPath = 'config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        // Verificar si la configuración ya existe
        if (strpos($twigContent, 'ModuloChat/templates') !== false) {
            $io->note('La configuración de Twig ya incluye las plantillas del módulo de chat.');
            return;
        }
        
        // Buscar un punto de inserción seguro
        if (!preg_match('/paths:/', $twigContent)) {
            $io->error('No se pudo encontrar la sección "paths" en twig.yaml');
            return;
        }
        
        $patterns = [
            "'%kernel.project_dir%/src/ModuloExplorador/templates': ~",
            "'%kernel.project_dir%/src/ModuloCore/templates': ~"
        ];
        
        $insertPoint = null;
        foreach ($patterns as $pattern) {
            if (strpos($twigContent, $pattern) !== false) {
                $insertPoint = $pattern;
                break;
            }
        }
        
        if ($insertPoint === null) {
            $io->warning('No se pudo encontrar un punto de inserción específico en twig.yaml.');
            
            // Insertar justo después de 'paths:'
            $newContent = preg_replace(
                '/(paths:)/i',
                "$1\n        '%kernel.project_dir%/src/ModuloChat/templates': ~",
                $twigContent
            );
            
            if ($newContent !== $twigContent) {
                file_put_contents($twigYamlPath, $newContent);
                $io->success('twig.yaml actualizado con las plantillas del módulo de chat.');
            } else {
                $io->error('No se pudo actualizar twig.yaml. Verifica el formato del archivo.');
            }
            return;
        }
        
        // Insertar después del punto encontrado
        $chatConfig = "\n        '%kernel.project_dir%/src/ModuloChat/templates': ~";
        $newContent = str_replace($insertPoint, $insertPoint . $chatConfig, $twigContent);
        
        if ($newContent !== $twigContent) {
            file_put_contents($twigYamlPath, $newContent);
            $io->success('twig.yaml actualizado con las plantillas del módulo de chat.');
        } else {
            $io->error('No se pudo actualizar twig.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function updateDoctrineYaml(SymfonyStyle $io): void
    {
        $doctrineYamlPath = 'config/packages/doctrine.yaml';
        $doctrineContent = file_get_contents($doctrineYamlPath);
        
        // Verificar si la configuración ya existe
        if (strpos($doctrineContent, 'ModuloChat') !== false) {
            $io->note('La configuración de Doctrine ya incluye las entidades del módulo de chat.');
            return;
        }
        
        // Buscar patrones de puntos de inserción seguros
        $patterns = [
            'ModuloExplorador:(\s+)type: attribute',
            'ModuloCore:(\s+)type: attribute'
        ];
        
        $insertPattern = null;
        $matches = [];
        
        foreach ($patterns as $pattern) {
            if (preg_match('/' . $pattern . '/s', $doctrineContent, $matchResult)) {
                $insertPattern = $pattern;
                $matches = $matchResult;
                break;
            }
        }
        
        if ($insertPattern === null) {
            $io->warning('No se pudo encontrar un punto de inserción seguro en doctrine.yaml.');
            if (!$io->confirm('¿Quieres continuar igualmente?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            if (preg_match('/mappings:(.*?)(\n\s+\w+:|$)/s', $doctrineContent, $mappingsMatch)) {
                $insertPos = strpos($doctrineContent, $mappingsMatch[0]) + strlen($mappingsMatch[0]) - strlen($mappingsMatch[2]);
                $chatConfig = $this->getChatDoctrineConfig(str_repeat(' ', 12)); // 12 espacios es la indentación estándar
                
                $newContent = substr($doctrineContent, 0, $insertPos) . $chatConfig . substr($doctrineContent, $insertPos);
                file_put_contents($doctrineYamlPath, $newContent);
                $io->success('doctrine.yaml actualizado con las entidades del módulo de chat.');
                return;
            }
            
            $io->error('No se pudo actualizar doctrine.yaml. La estructura del archivo no es compatible.');
            return;
        }
        
        $indentation = $matches[1] ?? str_repeat(' ', 12);
        
        $chatConfig = $this->getChatDoctrineConfig($indentation);
        
        $fullModulePattern = '/(' . $insertPattern . '.*?alias: \w+)/s';
        
        if (preg_match($fullModulePattern, $doctrineContent, $moduleMatches)) {
            $newContent = str_replace($moduleMatches[1], $moduleMatches[1] . "\n\n" . $chatConfig, $doctrineContent);
            file_put_contents($doctrineYamlPath, $newContent);
            $io->success('doctrine.yaml actualizado con las entidades del módulo de chat.');
        } else {
            $io->error('No se pudo actualizar doctrine.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getChatDoctrineConfig(string $indentation): string
    {
        return "{$indentation}ModuloChat:
{$indentation}    type: attribute
{$indentation}    is_bundle: false
{$indentation}    dir: '%kernel.project_dir%/src/ModuloChat/Entity'
{$indentation}    prefix: 'App\ModuloChat\Entity'
{$indentation}    alias: ModuloChat";
    }
}