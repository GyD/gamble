<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\User\User;
use App\Domain\User\UserStatus;
use App\Repository\UserAdministrationStore;
use App\Service\UserAdministrationService;
use App\Security\AuthorizationService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;
use ValueError;

final readonly class UserController
{
    public function __construct(
        private UserAdministrationStore $users,
        private UserAdministrationService $administration,
        private AuthorizationService $authorization,
        private Environment $twig,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'admin/users/index.html.twig', [
            'users' => $this->users->findAllWithRoles(),
            'current_user' => $this->actor($request),
            'can_manage_users' => $this->authorization->can($this->actor($request), 'users.manage'),
            'can_manage_permissions' => $this->authorization->can($this->actor($request), 'permissions.manage'),
            'saved' => isset($request->getQueryParams()['saved']),
        ]);
    }

    /** @param array<string, string> $args */
    public function changeStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $userId = $this->positiveId($args['id'] ?? '');
            $body = (array) $request->getParsedBody();
            $status = UserStatus::from(is_string($body['status'] ?? null) ? $body['status'] : '');
            $this->administration->changeStatus(
                $this->actor($request)->id,
                $userId,
                $status,
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException|ValueError $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $response->withStatus(303)->withHeader('Location', '/admin/users?saved=1');
    }

    /** @param array<string, string> $args */
    public function editAccess(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $userId = $this->positiveId($args['id'] ?? '');
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        $target = $this->users->findById($userId);
        if ($target === null) {
            return $response->withStatus(404);
        }

        return $this->render($request, $response, 'admin/users/access.html.twig', [
            'target' => $target,
            'current_user' => $this->actor($request),
            'roles' => $this->users->findAllRoles(),
            'permissions' => $this->users->findAllPermissions(),
            'selected_roles' => $this->users->roleNamesFor($userId),
            'permission_effects' => $this->users->permissionEffectsFor($userId),
            'saved' => isset($request->getQueryParams()['saved']),
        ]);
    }

    /** @param array<string, string> $args */
    public function updateAccess(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $userId = $this->positiveId($args['id'] ?? '');
            $body = (array) $request->getParsedBody();
            $roleNames = $this->stringList($body['roles'] ?? []);
            $effects = $this->effects($body['permissions'] ?? []);
            $this->administration->replaceAccess(
                $this->actor($request)->id,
                $userId,
                $roleNames,
                $effects,
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', sprintf('/admin/users/%d/access?saved=1', $userId));
    }

    private function actor(ServerRequestInterface $request): User
    {
        $actor = $request->getAttribute('user');
        if (!$actor instanceof User) {
            throw new InvalidArgumentException('Authentication required.');
        }

        return $actor;
    }

    private function positiveId(string $value): int
    {
        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid user identifier.');
        }

        return (int) $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Invalid role list.');
        }

        foreach ($value as $roleName) {
            if (!is_string($roleName)) {
                throw new InvalidArgumentException('Invalid role value.');
            }
        }

        return array_values(array_unique($value));
    }

    /** @return array<string, string> */
    private function effects(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Invalid permission list.');
        }

        $effects = [];
        foreach ($value as $permission => $effect) {
            if (!is_string($permission) || !is_string($effect)) {
                throw new InvalidArgumentException('Invalid permission value.');
            }

            if ($effect === 'inherit') {
                continue;
            }

            if (!in_array($effect, ['allow', 'deny'], true)) {
                throw new InvalidArgumentException('Invalid permission effect.');
            }

            $effects[$permission] = $effect;
        }

        return $effects;
    }

    /** @param array<string, mixed> $context */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $context,
    ): ResponseInterface {
        $context['csrf'] = [
            'name_key' => $request->getAttribute('csrf_name'),
            'name' => $request->getAttribute('csrf_name_value'),
            'value_key' => $request->getAttribute('csrf_value'),
            'value' => $request->getAttribute('csrf_value_value'),
        ];
        $response->getBody()->write($this->twig->render($template, $context));

        return $response;
    }

    private function badRequest(ResponseInterface $response, string $message): ResponseInterface
    {
        $response->getBody()->write($message);

        return $response->withStatus(400);
    }

    private function ipAddress(ServerRequestInterface $request): ?string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) ? $address : null;
    }
}