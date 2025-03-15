<?php

namespace App\ModuloChat\Command;

use App\ModuloCore\Entity\Modulo;
use App\ModuloCore\Entity\MenuElement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'modulochat:deactivate',
    description: 'Desactiva temporalmente el módulo de chat sin eliminarlo'
)]
class ChatDeactivateCommand extends Command
{
    private EntityManagerInterface $entityManager;
    
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->addOption('config-only', null, InputOption::VALUE_NONE, 'Solo desactivar configuraciones, manteniendo el módulo en base de datos')
            ->addOption('db-only', null, InputOption::VALUE_NONE, 'Solo desactivar en base de datos, manteniendo las configuraciones')
            ->setHelp(<<<EOT
                El comando <info>modulochat:deactivate</info> realiza lo siguiente:

                1. Comenta las rutas del módulo de chat en routes.yaml
                2. Comenta los servicios del módulo de chat en services.yaml
                3. Comenta la configuración de Twig del módulo de chat
                4. Desactiva el módulo en la base de datos
                5. Desactiva los elementos de menú asociados al módulo

                Opciones:
                  --config-only    Solo desactivar configuraciones, manteniendo el módulo en base de datos
                  --db-only        Solo desactivar en base de datos, manteniendo las configuraciones

                Ejemplo de uso:

                <info>php bin/console modulochat:deactivate</info>
                <info>php bin/console modulochat:deactivate --config-only</info>
                <info>php bin/console modulochat:deactivate --db-only</info>
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Desactivación del Módulo de Chat');
        
        $configOnly = $input->getOption('config-only');
        $dbOnly = $input->getOption('db-only');
        
        if ($configOnly && $dbOnly) {
            $io->error('No puedes usar --config-only y --db-only al mismo tiempo.');
            return Command::FAILURE;
        }
        
        if (!$dbOnly) {
            // 1. Desactivar rutas
            $this->deactivateRoutes($io);
            
            // 2. Desactivar servicios
            $this->deactivateServices($io);
            
            // 3. Desactivar Twig
            $this->deactivateTwig($io);
        }
        
        if (!$configOnly) {
            // 4. Desactivar en la base de datos
            $this->deactivateModule($io);
            
            // 5. Desactivar elementos de menú
            $this->deactivateMenuItems($io);
        }
        
        if ($configOnly) {
            $io->success('El módulo de chat ha sido desactivado a nivel de configuración.');
        } elseif ($dbOnly) {
            $io->success('El módulo de chat ha sido desactivado en la base de datos.');
        } else {
            $io->success('El módulo de chat ha sido desactivado completamente.');
        }
        
        $io->note('Para reactivar el módulo, ejecuta: php bin/console modulochat:activate');
        
        return Command::SUCCESS;
    }
    
    private function deactivateRoutes(SymfonyStyle $io): void
    {
        $routesYamlPath = 'config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        // Buscar las rutas del módulo de chat
        $pattern = "/modulo_chat_controllers:(.*?)type: attribute/s";
        
        // Comprobar si las rutas ya están comentadas
        if (preg_match("/# modulo_chat_controllers:/", $routesContent)) {
            $io->note('Las rutas del módulo de chat ya estaban desactivadas.');
            return;
        }
        
        // Comentar las rutas si existen
        if (preg_match($pattern, $routesContent)) {
            $routesContent = preg_replace(
                $pattern, 
                "# modulo_chat_controllers: DESACTIVADO\n# resource:\n#     path: ../src/ModuloChat/Controller/\n#     namespace: App\ModuloChat\Controller\n# type: attribute", 
                $routesContent
            );
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Las rutas del módulo de chat han sido desactivadas.');
        } else {
            $io->note('No se encontraron rutas del módulo de chat para desactivar.');
        }
    }
    
    private function deactivateServices(SymfonyStyle $io): void
    {
        $servicesYamlPath = 'config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);
        
        // Patrón específico que coincide con la sección de ModuloChat
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
        if (strpos($twigContent, "'%kernel.project_dir%/src/ModuloChat/templates': ModuloChat") !== false 
            && strpos($twigContent, "# '%kernel.project_dir%/src/ModuloChat/templates'") === false) {
            $twigContent = str_replace(
                "'%kernel.project_dir%/src/ModuloChat/templates': ModuloChat",
                "# '%kernel.project_dir%/src/ModuloChat/templates': ModuloChat # DESACTIVADO",
                $twigContent
            );
            file_put_contents($twigYamlPath, $twigContent);
            $io->success('Las plantillas Twig del módulo de chat han sido desactivadas.');
        } else {
            $io->note('Las plantillas Twig del módulo de chat ya estaban desactivadas o no se encontraron.');
        }
    }
    
    private function deactivateModule(SymfonyStyle $io): void
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $chatModule = $moduloRepository->findOneBy(['nombre' => 'Chat']);
            
            if ($chatModule) {
                if (!$chatModule->isEstado()) {
                    $io->note('El módulo Chat ya estaba desactivado en la base de datos.');
                } else {
                    $chatModule->setEstado(false);
                    $this->entityManager->flush();
                    $io->success('El módulo Chat ha sido desactivado en la base de datos.');
                }
            } else {
                $io->note('No se encontró el módulo Chat en la base de datos.');
            }
        } catch (\Exception $e) {
            $io->error('Error al desactivar el módulo en la base de datos: ' . $e->getMessage());
        }
    }
    
    private function deactivateMenuItems(SymfonyStyle $io): void
    {
        try {
            $menuRepository = $this->entityManager->getRepository(MenuElement::class);
            $moduleRepository = $this->entityManager->getRepository(Modulo::class);
            
            // Buscar el módulo Chat
            $chatModule = $moduleRepository->findOneBy(['nombre' => 'Chat']);
            
            if (!$chatModule) {
                $io->note('No se encontró el módulo Chat en la base de datos para desactivar elementos de menú.');
                return;
            }
            
            // Buscar elementos de menú asociados al módulo Chat
            $menuItems = $menuRepository->createQueryBuilder('m')
                ->innerJoin('m.modulo', 'mod')
                ->where('mod.id = :moduleId')
                ->setParameter('moduleId', $chatModule->getId())
                ->getQuery()
                ->getResult();
            
            if (empty($menuItems)) {
                // También buscar por nombre para elementos no asociados directamente
                $menuItems = $menuRepository->findBy(['nombre' => 'Chat']);
                
                if (empty($menuItems)) {
                    $io->note('No se encontraron elementos de menú asociados al módulo Chat.');
                    return;
                }
            }
            
            $count = 0;
            foreach ($menuItems as $menuItem) {
                if ($menuItem->isEnabled()) {
                    $menuItem->setEnabled(false);
                    $count++;
                }
            }
            
            if ($count > 0) {
                $this->entityManager->flush();
                $io->success("Se han desactivado {$count} elementos de menú asociados al módulo Chat.");
            } else {
                $io->note('Todos los elementos de menú asociados al módulo Chat ya estaban desactivados.');
            }
            
        } catch (\Exception $e) {
            $io->error('Error al desactivar elementos de menú: ' . $e->getMessage());
        }
    }
}