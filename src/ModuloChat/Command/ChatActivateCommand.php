<?php

namespace App\ModuloChat\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'modulochat:activate',
    description: 'Reactiva el módulo de chat previamente desactivado'
)]
class ChatActivateCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp(<<<EOT
            El comando <info>modulochat:activate</info> realiza lo siguiente:

            1. Descomenta las rutas del módulo de chat en routes.yaml
            2. Descomenta los servicios del módulo de chat en services.yaml
            3. Descomenta la configuración de Twig del módulo de chat

            Esto reactiva el módulo sin necesidad de reinstalarlo.

            Ejemplo de uso:

            <info>php bin/console modulochat:activate</info>
            EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // 1. Activar rutas
        $this->activateRoutes($io);
        
        // 2. Activar servicios
        $this->activateServices($io);
        
        // 3. Activar Twig
        $this->activateTwig($io);
        
        $io->success('El módulo de chat ha sido activado correctamente.');
        
        return Command::SUCCESS;
    }
    
    private function activateRoutes(SymfonyStyle $io): void
    {
        $routesYamlPath = 'config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        // Buscar rutas comentadas
        if (strpos($routesContent, "# modulo_chat_controllers") !== false) {
            $routesContent = str_replace(
                [
                    "# modulo_chat_controllers: DESACTIVADO", 
                    "# resource:", 
                    "#     path: ../src/ModuloChat/Controller/", 
                    "#     namespace: App\ModuloChat\Controller", 
                    "# type: attribute"
                ],
                [
                    "modulo_chat_controllers:", 
                    "    resource:", 
                    "        path: ../src/ModuloChat/Controller/", 
                    "        namespace: App\ModuloChat\Controller", 
                    "    type: attribute"
                ],
                $routesContent
            );
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Las rutas del módulo de chat han sido activadas.');
        } else {
            $io->note('No se encontraron rutas del módulo de chat desactivadas.');
        }
    }
    
    private function activateServices(SymfonyStyle $io): void
    {
        $servicesYamlPath = 'config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);
        
        // Buscar la sección del módulo Chat desactivado
        $pattern = "/#START.*?ModuloChat \(DESACTIVADO\).*?\n(.*?)#END.*?ModuloChat.*?\n/s";
        
        if (preg_match($pattern, $servicesContent)) {
            // Extraer la sección completa
            preg_match($pattern, $servicesContent, $matches);
            $disabledSection = $matches[0];
            $disabledContent = $matches[1];
            
            // Descomentar cada línea (quitar los # que están al principio de cada línea real)
            $enabledContent = preg_replace('/^(\s+)#(\S)/m', '$1$2', $disabledContent);
            
            // Reemplazar la sección completa con la versión activada
            $enabledSection = "#START -----------------------------------------------------  ModuloChat -------------------------------------------------------------------------- \n";
            $enabledSection .= $enabledContent;
            $enabledSection .= "#END ------------------------------------------------------- ModuloChat -----------------------------------------------------------------------------\n";
            
            $newContent = str_replace($disabledSection, $enabledSection, $servicesContent);
            
            file_put_contents($servicesYamlPath, $newContent);
            $io->success('Los servicios del módulo de chat han sido activados.');
        } else {
            $io->note('No se encontraron servicios del módulo de chat desactivados.');
        }
    }
    
    private function activateTwig(SymfonyStyle $io): void
    {
        $twigYamlPath = 'config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        // Buscar plantillas comentadas
        if (strpos($twigContent, "# '%kernel.project_dir%/src/ModuloChat/templates'") !== false) {
            $twigContent = str_replace(
                "# '%kernel.project_dir%/src/ModuloChat/templates': ~ # DESACTIVADO",
                "'%kernel.project_dir%/src/ModuloChat/templates': ~",
                $twigContent
            );
            file_put_contents($twigYamlPath, $twigContent);
            $io->success('Las plantillas Twig del módulo de chat han sido activadas.');
        } else {
            $io->note('No se encontraron plantillas Twig del módulo de chat desactivadas.');
        }
    }
}