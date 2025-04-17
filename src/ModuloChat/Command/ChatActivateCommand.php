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
    name: 'modulochat:activate',
    description: 'Reactiva el módulo de chat previamente desactivado'
)]
class ChatActivateCommand extends Command
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
            ->addOption('config-only', null, InputOption::VALUE_NONE, 'Solo activar configuraciones, sin modificar el módulo en base de datos')
            ->addOption('db-only', null, InputOption::VALUE_NONE, 'Solo activar en base de datos, sin modificar configuraciones')
            ->setHelp(<<<EOT
                El comando <info>modulochat:activate</info> realiza lo siguiente:

                1. Descomenta las rutas del módulo de chat en routes.yaml
                2. Descomenta los servicios del módulo de chat en services.yaml
                3. Descomenta la configuración de Twig del módulo de chat
                4. Activa el módulo en la base de datos
                5. Activa los elementos de menú asociados al módulo

                Opciones:
                  --config-only    Solo activar configuraciones, sin modificar el módulo en base de datos
                  --db-only        Solo activar en base de datos, sin modificar configuraciones

                Ejemplo de uso:

                <info>php bin/console modulochat:activate</info>
                <info>php bin/console modulochat:activate --config-only</info>
                <info>php bin/console modulochat:activate --db-only</info>
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Activación del Módulo de Chat');
        
        $configOnly = $input->getOption('config-only');
        $dbOnly = $input->getOption('db-only');
        
        if ($configOnly && $dbOnly) {
            $io->error('No puedes usar --config-only y --db-only al mismo tiempo.');
            return Command::FAILURE;
        }
        
        if (!$dbOnly) {
            // 1. Activar rutas
            $this->activateRoutes($io);
            
            // 2. Activar servicios
            $this->activateServices($io);
            
            // 3. Activar Twig
            $this->activateTwig($io);
        }
        
        if (!$configOnly) {
            // 4. Activar en la base de datos
            $this->activateModule($io);
            
            // 5. Activar elementos de menú
            $this->activateMenuItems($io);
        }
        
        if ($configOnly) {
            $io->success('El módulo de chat ha sido activado a nivel de configuración.');
        } elseif ($dbOnly) {
            $io->success('El módulo de chat ha sido activado en la base de datos.');
        } else {
            $io->success('El módulo de chat ha sido activado completamente.');
        }
        
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
        
        if (preg_match($pattern, $servicesContent, $matches)) {
            // Extraer la sección completa
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
            // También verificar si ya están activos
            $activePattern = "/#START.*?ModuloChat[^(]*?\n.*?#END.*?ModuloChat.*?\n/s";
            if (preg_match($activePattern, $servicesContent)) {
                $io->note('Los servicios del módulo de chat ya están activos.');
            } else {
                $io->note('No se encontraron servicios del módulo de chat para activar.');
            }
        }
    }
    
    private function activateTwig(SymfonyStyle $io): void
    {
        $twigYamlPath = 'config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        // Buscar plantillas comentadas
        if (strpos($twigContent, "# '%kernel.project_dir%/src/ModuloChat/templates'") !== false) {
            $twigContent = str_replace(
                "# '%kernel.project_dir%/src/ModuloChat/templates': ModuloChat # DESACTIVADO",
                "'%kernel.project_dir%/src/ModuloChat/templates': ModuloChat",
                $twigContent
            );
            file_put_contents($twigYamlPath, $twigContent);
            $io->success('Las plantillas Twig del módulo de chat han sido activadas.');
        } else {
            // Verificar si ya están activas
            if (strpos($twigContent, "'%kernel.project_dir%/src/ModuloChat/templates': ModuloChat") !== false) {
                $io->note('Las plantillas Twig del módulo de chat ya están activas.');
            } else {
                $io->note('No se encontraron plantillas Twig del módulo de chat para activar.');
            }
        }
    }
    
    private function activateModule(SymfonyStyle $io): void
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $chatModule = $moduloRepository->findOneBy(['nombre' => 'Chat']);
            
            if ($chatModule) {
                if ($chatModule->isEstado()) {
                    $io->note('El módulo Chat ya estaba activado en la base de datos.');
                } else {
                    $chatModule->setEstado(true);
                    $this->entityManager->flush();
                    $io->success('El módulo Chat ha sido activado en la base de datos.');
                }
            } else {
                $io->warning('No se encontró el módulo Chat en la base de datos. Considera ejecutar modulochat:setup para instalarlo completamente.');
            }
        } catch (\Exception $e) {
            $io->error('Error al activar el módulo en la base de datos: ' . $e->getMessage());
        }
    }
    
    private function activateMenuItems(SymfonyStyle $io): void
    {
        try {
            $menuRepository = $this->entityManager->getRepository(MenuElement::class);
            $moduleRepository = $this->entityManager->getRepository(Modulo::class);
            
            // Buscar el módulo Chat
            $chatModule = $moduleRepository->findOneBy(['nombre' => 'Chat']);
            
            if (!$chatModule) {
                $io->note('No se encontró el módulo Chat en la base de datos para activar elementos de menú.');
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
                    $io->note('No se encontraron elementos de menú asociados al módulo Chat. Considera crear uno manualmente.');
                    return;
                }
            }
            
            $count = 0;
            foreach ($menuItems as $menuItem) {
                if (!$menuItem->isEnabled()) {
                    $menuItem->setEnabled(true);
                    $count++;
                }
            }
            
            if ($count > 0) {
                $this->entityManager->flush();
                $io->success("Se han activado {$count} elementos de menú asociados al módulo Chat.");
            } else {
                $io->note('Todos los elementos de menú asociados al módulo Chat ya estaban activados.');
            }
            
        } catch (\Exception $e) {
            $io->error('Error al activar elementos de menú: ' . $e->getMessage());
        }
    }
}