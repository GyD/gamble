<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Group\Group;
use App\Domain\User\User;
use App\Repository\ContactStore;
use App\Repository\GroupStore;
use App\Security\AuthorizationService;
use App\Service\ContactService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class ContactController
{
    public function __construct(
        private ContactStore $contacts,
        private ContactService $service,
        private AuthorizationService $authorization,
        private Environment $twig,
        private GroupStore $groups,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = $this->actor($request);

        return $this->render($request, $response, 'contacts/index.html.twig', [
            'contacts' => $this->contacts->findAll(),
            'groups' => array_values(array_filter(
                $this->groups->findAll(),
                static fn(Group $group): bool => !$group->isArchived(),
            )),
            'can_create' => $this->authorization->can($actor, 'contacts.create'),
            'can_edit' => $this->authorization->can($actor, 'contacts.edit'),
            'can_archive' => $this->authorization->can($actor, 'contacts.delete'),
            'saved' => isset($request->getQueryParams()['saved']),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $body = (array) $request->getParsedBody();
            $this->service->create(
                $this->actor($request)->id,
                $this->stringValue($body, 'name'),
                $this->stringValue($body, 'phone_number'),
                $this->nullableStringValue($body, 'note'),
                $this->ipAddress($request),
                $this->arrayValue($body, 'group_ids'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $response->withStatus(303)->withHeader('Location', '/contacts?saved=1');
    }

    /** @param array<string, string> $args */
    public function edit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $contact = $this->contacts->findById($this->positiveId($args['id'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        if ($contact === null) {
            return $response->withStatus(404);
        }

        return $this->render($request, $response, 'contacts/edit.html.twig', [
            'contact' => $contact,
            'groups' => $this->groups->findAvailableForContact($contact->id),
            'selected_group_ids' => $this->groups->memberGroupIds($contact->id),
        ]);
    }

    /** @param array<string, string> $args */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $contactId = $this->positiveId($args['id'] ?? '');
            if ($this->contacts->findById($contactId) === null) {
                return $response->withStatus(404);
            }
            $body = (array) $request->getParsedBody();
            $this->service->update(
                $this->actor($request)->id,
                $contactId,
                $this->stringValue($body, 'name'),
                $this->stringValue($body, 'phone_number'),
                $this->nullableStringValue($body, 'note'),
                $this->ipAddress($request),
                $this->arrayValue($body, 'group_ids'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $response->withStatus(303)->withHeader('Location', '/contacts?saved=1');
    }

    /** @param array<string, string> $args */
    public function archive(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $contactId = $this->positiveId($args['id'] ?? '');
            if ($this->contacts->findById($contactId) === null) {
                return $response->withStatus(404);
            }
            $body = (array) $request->getParsedBody();
            $archived = match ($this->stringValue($body, 'archived')) {
                '1' => true,
                '0' => false,
                default => throw new InvalidArgumentException('Invalid archive status.'),
            };
            $this->service->setArchived(
                $this->actor($request)->id,
                $contactId,
                $archived,
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $response->withStatus(303)->withHeader('Location', '/contacts?saved=1');
    }

    /** @param array<string, string> $args */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $contactId = $this->positiveId($args['id'] ?? '');
            if ($this->contacts->findById($contactId) === null) {
                return $response->withStatus(404);
            }
            $this->service->delete(
                $this->actor($request)->id,
                $contactId,
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $response->withStatus(303)->withHeader('Location', '/contacts?saved=1');
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
            throw new InvalidArgumentException('Invalid contact identifier.');
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $body */
    private function stringValue(array $body, string $key): string
    {
        if (!is_string($body[$key] ?? null)) {
            throw new InvalidArgumentException(sprintf('Invalid %s.', $key));
        }

        return $body[$key];
    }

    /** @param array<string, mixed> $body */
    private function nullableStringValue(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException(sprintf('Invalid %s.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $body @return array<mixed>|null */
    private function arrayValue(array $body, string $key): ?array
    {
        if (!array_key_exists($key, $body)) {
            return [];
        }
        if (!is_array($body[$key])) {
            throw new InvalidArgumentException(sprintf('Invalid %s.', $key));
        }

        return $body[$key];
    }

    /** @param array<string, mixed> $context */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $context,
    ): ResponseInterface {
        $actor = $this->actor($request);
        $context['can_view_bets'] = $this->authorization->can($actor, 'bets.view');
        $context['can_view_contacts'] = $this->authorization->can($actor, 'contacts.view');
        $context['can_view_groups'] = $this->authorization->can($actor, 'groups.view');
        $context['can_view_statistics'] = $this->authorization->can($actor, 'statistics.view');
        $context['can_view_users'] = $this->authorization->can($actor, 'users.view');
        $context['csrf'] = [
            'name_key' => 'csrf_name',
            'name' => $request->getAttribute('csrf_name'),
            'value_key' => 'csrf_value',
            'value' => $request->getAttribute('csrf_value'),
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
