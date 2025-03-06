<?php

namespace App\ModuloChat\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'modulochat:deactivate',
    description: 'Desactiva temporalmente el módulo de chat sin eliminarlo'
)]
class ChatDeactivateCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp(<<<EOT
            El comando <info>modulochat:deactivate</info> realiza lo siguiente:

            1. Comenta las rutas del módulo de chat en routes.yaml
            2. Comenta los servicios del módulo de chat en services.yaml
            3. Comenta la configuración de Twig del módulo de chat

            Esto desactiva el módulo sin eliminar ningún dato, permitiendo reactivarlo después.

            Ejemplo de uso:

            <info>php bin/console modulochat:deactivate</info>
            EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // 1. Desactivar rutas
        $this->deactivateRoutes($io);
        
        // 2. Desactivar servicios
        $this->deactivateServices($io);
        
        // 3. Desactivar Twig
        $this->deactivateTwig($io);
        
        $io->success('El módulo de chat ha sido desactivado correctamente.');
        $io->note('Para reactivar el módulo, ejecuta: php bin/console modulochat:activate');
        
        return Command::SUCCESS;
    }
    
    private function deactivateRoutes(SymfonyStyle $io): void
    {
        $routesYamlPath = 'config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        // Buscar las rutas del módulo de chat
        $pattern = "/modulo_chat_controllers:(.*?)type: attribute/s";
        
        // Comentar las rutas si existen
        if (preg_match($pattern, $routesContent) && !preg_match("/# modulo_chat_controllers:/", $routesContent)) {
            $routesContent = preg_replace(
                $pattern, 
                "# modulo_chat_controllers: DESACTIVADO\n# resource:\n#     path: ../src/ModuloChat/Controller/\n#     namespace: App\ModuloChat\Controller\n# type: attribute", 
                $routesContent
            );
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Las rutas del módulo de chat han sido desactivadas.');
        } else {
            $io->note('Las rutas del módulo de chat ya estaban desactivadas o no se encontraron.');
        }
    }
    
    private function deactivateServices(SymfonyStyle $io): void
    {
        $servicesYamlPath = 'config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);
        
        // Patrón mucho más específico que coincide exactamente con la sección de ModuloChat
        $pattern = "/#START\s+----+\s+ModuloChat\s+----+\s+\n(.*?)#END\s+----+\s+ModuloChat\s+----+/s";
        
        if (preg_match($pattern, $servicesContent, $matches) && 
            strpos($matches[0], "(DESACTIVADO)") === false) { // Verificar que no esté ya desactivado
            
            $chatSection = $matches[0];
            $chatContent = $matches[1];
            
            // Comentar cada línea no vacía que no comience ya con un #
            $commentedContent = preg_replace('/^(\s+)(?!#)(\S)/m', '$1#$2', $chatContent);
            
            // Reemplazar la sección completa con la versión comentada
            $replacedSection = "#START -----------------------------------------------------  ModuloChat (DESACTIVADO) -------------------------------------------------------------------------- \n";
            $replacedSection .= $commentedContent;
            $replacedSection .= "#END ------------------------------------------------------- ModuloChat -----------------------------------------------------------------------------";
            
            // Reemplazar solo la sección exacta
            $newContent = str_replace($chatSection, $replacedSection, $servicesContent);
            
            // Verificación de seguridad: asegurarse de que solo estamos modificando la sección correcta
            if (substr_count($servicesContent, "#START") === substr_count($newContent, "#START") &&
                substr_count($servicesContent, "#END") === substr_count($newContent, "#END")) {
                
                file_put_contents($servicesYamlPath, $newContent);
                $io->success('Los servicios del módulo de chat han sido desactivados correctamente.');
            } else {
                $io->error('No se pudo desactivar con seguridad los servicios del módulo de chat. El patrón podría afectar a otros módulos.');
            }
        } else {
            $io->note('Los servicios del módulo de chat ya estaban desactivados o no se encontraron.');
        }
    }
    
    private function deactivateTwig(SymfonyStyle $io): void
    {
        $twigYamlPath = 'config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        // Buscar la configuración de Twig del módulo de chat
        if (strpos($twigContent, "'%kernel.project_dir%/src/ModuloChat/templates'") !== false 
            && strpos($twigContent, "# '%kernel.project_dir%/src/ModuloChat/templates'") === false) {
            $twigContent = str_replace(
                "'%kernel.project_dir%/src/ModuloChat/templates': ~",
                "# '%kernel.project_dir%/src/ModuloChat/templates': ~ # DESACTIVADO",
                $twigContent
            );
            file_put_contents($twigYamlPath, $twigContent);
            $io->success('Las plantillas Twig del módulo de chat han sido desactivadas.');
        } else {
            $io->note('Las plantillas Twig del módulo de chat ya estaban desactivadas o no se encontraron.');
        }
    }
}