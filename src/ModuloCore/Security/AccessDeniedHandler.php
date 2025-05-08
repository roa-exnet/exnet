<?php
namespace App\ModuloCore\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

class AccessDeniedHandler implements AccessDeniedHandlerInterface
{
    private RouterInterface $router;

    public function __construct(RouterInterface $router)
    {
        $this->router = $router;
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        if ($request->cookies->has('module_token')) {
            return new Response('Access Denied', 403);
        }
    
        $path = $request->getPathInfo();
        $modulo = explode('/', trim($path, '/'))[0] ?? 'default';
        $redirect = $request->getRequestUri();
    
        $url = $this->router->generate('generar_cookie', [
            'modulo' => $modulo,
        ]) . '?redirect=' . urlencode($redirect);
    
        return new RedirectResponse($url);
    }
}
