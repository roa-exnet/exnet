<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\ModuloCore\Entity\User;

class SimpleTest
{
    private $passedTests = 0;
    private $failedTests = 0;
    private $totalTests = 0;

    public function run()
    {
        echo "Ejecutando pruebas unitarias simples...\n";
        echo "======================================\n\n";

        $this->testUserEntity();

        $this->testEmailValidation();

        $this->testUserRoles();

        echo "\n======================================\n";
        echo "RESULTADOS: {$this->passedTests} pasadas, {$this->failedTests} fallidas (Total: {$this->totalTests})\n";

        return $this->failedTests === 0;
    }

    private function assert($condition, $message)
    {
        $this->totalTests++;

        if ($condition) {
            echo "✅ PASS: $message\n";
            $this->passedTests++;
        } else {
            echo "❌ FAIL: $message\n";
            $this->failedTests++;
        }
    }

    private function testUserEntity()
    {
        echo "▶️ Prueba de User Entity\n";

        $user = new User();
        $user->setEmail('test@example.com');
        $user->setNombre('Test');
        $user->setApellidos('User');
        $user->setRoles(['ROLE_USER']);

        $this->assert($user->getEmail() === 'test@example.com', 'User->getEmail() devuelve el email correcto');
        $this->assert($user->getNombre() === 'Test', 'User->getNombre() devuelve el nombre correcto');
        $this->assert($user->getApellidos() === 'User', 'User->getApellidos() devuelve los apellidos correctos');

        $roles = $user->getRoles();
        $this->assert(in_array('ROLE_USER', $roles), 'User->getRoles() incluye ROLE_USER');

        $this->assert($user->isIsActive() === true, 'User->isIsActive() devuelve true por defecto');

        echo "\n";
    }

    private function testEmailValidation()
    {
        echo "▶️ Prueba de Validación de Email\n";

        $validEmails = [
            'usuario@dominio.com',
            'usuario.nombre@dominio.com',
            'usuario+etiqueta@dominio.com',
            'usuario@sub.dominio.com',
            'usuario@dominio.es'
        ];

        $invalidEmails = [
            'usuario',
            'usuario@',
            '@dominio.com',
            'usuario@dominio',
            'usuario@.com',
            'usuario@dominio..com'
        ];

        foreach ($validEmails as $email) {
            $isValid = filter_var($email, FILTER_VALIDATE_EMAIL);
            $this->assert($isValid !== false, "Email válido reconocido correctamente: $email");
        }

        foreach ($invalidEmails as $email) {
            $isValid = filter_var($email, FILTER_VALIDATE_EMAIL);
            $this->assert($isValid === false, "Email inválido rechazado correctamente: $email");
        }

        echo "\n";
    }

    private function testUserRoles()
    {
        echo "▶️ Prueba de Roles de Usuario\n";

        $regularUser = new User();
        $regularUser->setRoles(['ROLE_USER']);
        $roles = $regularUser->getRoles();

        $this->assert(in_array('ROLE_USER', $roles), 'Usuario regular tiene ROLE_USER');
        $this->assert(!in_array('ROLE_ADMIN', $roles), 'Usuario regular no tiene ROLE_ADMIN');

        $adminUser = new User();
        $adminUser->setRoles(['ROLE_ADMIN']);
        $roles = $adminUser->getRoles();

        $this->assert(in_array('ROLE_USER', $roles), 'Usuario admin tiene ROLE_USER implícito');
        $this->assert(in_array('ROLE_ADMIN', $roles), 'Usuario admin tiene ROLE_ADMIN');

        $multiRoleUser = new User();
        $multiRoleUser->setRoles(['ROLE_USER', 'ROLE_EDITOR', 'ROLE_MODERATOR']);
        $roles = $multiRoleUser->getRoles();

        $this->assert(count($roles) === 3, 'Usuario con múltiples roles tiene la cantidad correcta de roles');
        $this->assert(in_array('ROLE_USER', $roles), 'Usuario multi-rol tiene ROLE_USER');
        $this->assert(in_array('ROLE_EDITOR', $roles), 'Usuario multi-rol tiene ROLE_EDITOR');
        $this->assert(in_array('ROLE_MODERATOR', $roles), 'Usuario multi-rol tiene ROLE_MODERATOR');

        echo "\n";
    }
}

$test = new SimpleTest();
$result = $test->run();

exit($result ? 0 : 1);