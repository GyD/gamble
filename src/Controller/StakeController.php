<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetStatus;
use App\Domain\User\User;
use App\Repository\BetStore;
use App\Repository\ContactStore;
use App\Repository\GroupStore;
use App\Repository\StakeStore;
use App\Security\AuthorizationService;
use App\Service\StakeService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class StakeController
{
    public function __construct(
        private BetStore             $bets,
        private StakeStore           $stakes,
        private ContactStore         $contacts,
        private GroupStore           $groups,
        private StakeService         $service,
        private AuthorizationService $authorization,
        private Environment          $twig,
    )
    {
    }

    /** @param array<string, string> $args */
    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $bet = $this->accessibleBet($this->positiveId($args['id'] ?? '', 'bet'));
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        $actor = $this->actor($request);
        $isMutable = $bet->status === BetStatus::Open;
        $allContacts = $this->contacts->findAll();
        $contacts = array_values(array_filter(
            $allContacts,
            static fn($contact): bool => !$contact->isArchived(),
        ));
        $groupsById = [];
        foreach ($this->groups->findAll() as $group) {
            $groupsById[$group->id] = $group;
        }
        $contactGroups = [];
        foreach ($allContacts as $contact) {
            foreach ($this->groups->memberGroupIds($contact->id) as $groupId) {
                if (isset($groupsById[$groupId])) {
                    $contactGroups[$contact->id][] = $groupsById[$groupId]->name;
                }
            }
        }

        return $this->render($request, $response, 'stakes/index.html.twig', [
            'bet' => $this->service->withOdds($bet),
            'stakes' => $this->stakes->findByBet($bet->id),
            'contacts' => $contacts,
            'contact_groups' => $contactGroups,
            'can_create' => $isMutable && $this->authorization->can($actor, 'stakes.create'),
            'can_edit' => $isMutable && $this->authorization->can($actor, 'stakes.edit'),
            'can_delete' => $isMutable && $this->authorization->can($actor, 'stakes.delete'),
            'can_refund' => $bet->status === BetStatus::Cancelled
                && $this->authorization->can($actor, 'stakes.edit'),
            'saved' => isset($request->getQueryParams()['saved']),
        ]);
    }

    /** @param array<string, string> $args */
    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $body = (array)$request->getParsedBody();
            $betId = $this->positiveId($args['id'] ?? '', 'bet');
            $this->service->create(
                $this->actor($request)->id,
                $betId,
                $this->bodyId($body, 'contact_id'),
                $this->bodyId($body, 'bet_option_id'),
                $this->stringValue($body, 'amount'),
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response, $betId);
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $body = (array)$request->getParsedBody();
            $betId = $this->positiveId($args['id'] ?? '', 'bet');
            $this->service->update(
                $this->actor($request)->id,
                $betId,
                $this->positiveId($args['stakeId'] ?? '', 'stake'),
                $this->bodyId($body, 'contact_id'),
                $this->bodyId($body, 'bet_option_id'),
                $this->stringValue($body, 'amount'),
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response, $betId);
    }

    /** @param array<string, string> $args */
    public function setPaid(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $body = (array)$request->getParsedBody();
            $betId = $this->positiveId($args['id'] ?? '', 'bet');
            $isPaid = $this->stringValue($body, 'is_paid');
            if (!in_array($isPaid, ['0', '1'], true)) {
                throw new InvalidArgumentException('Invalid payment status.');
            }
            $this->service->setPaid(
                $this->actor($request)->id,
                $betId,
                $this->positiveId($args['stakeId'] ?? '', 'stake'),
                $isPaid === '1',
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response, $betId);
    }

    /** @param array<string, string> $args */
    public function setCancelled(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $body = (array)$request->getParsedBody();
            $betId = $this->positiveId($args['id'] ?? '', 'bet');
            $isCancelled = $this->stringValue($body, 'is_cancelled');
            if (!in_array($isCancelled, ['0', '1'], true)) {
                throw new InvalidArgumentException('Invalid cancellation status.');
            }
            $this->service->setCancelled(
                $this->actor($request)->id,
                $betId,
                $this->positiveId($args['stakeId'] ?? '', 'stake'),
                $isCancelled === '1',
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response, $betId);
    }

    /** @param array<string, string> $args */
    public function setRefunded(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $body = (array)$request->getParsedBody();
            $betId = $this->positiveId($args['id'] ?? '', 'bet');
            $isRefunded = $this->stringValue($body, 'is_refunded');
            if (!in_array($isRefunded, ['0', '1'], true)) {
                throw new InvalidArgumentException('Invalid refund status.');
            }
            $this->service->setRefunded(
                $this->actor($request)->id,
                $betId,
                $this->positiveId($args['stakeId'] ?? '', 'stake'),
                $isRefunded === '1',
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response, $betId);
    }

    /** @param array<string, string> $args */
    public function setWinningsPaid(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $body = (array)$request->getParsedBody();
            $betId = $this->positiveId($args['id'] ?? '', 'bet');
            $isPaid = $this->stringValue($body, 'is_paid');
            if (!in_array($isPaid, ['0', '1'], true)) {
                throw new InvalidArgumentException('Invalid winnings payment status.');
            }
            $this->service->setWinningsPaid(
                $this->actor($request)->id,
                $betId,
                $this->positiveId($args['contactId'] ?? '', 'contact'),
                $isPaid === '1',
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $response->withStatus(303)->withHeader('Location', sprintf('/bets/%d?saved=1', $betId));
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $betId = $this->positiveId($args['id'] ?? '', 'bet');
            $this->service->delete(
                $this->actor($request)->id,
                $betId,
                $this->positiveId($args['stakeId'] ?? '', 'stake'),
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response, $betId);
    }

    private function accessibleBet(int $betId): Bet
    {
        return $this->bets->findById($betId) ?? throw new InvalidArgumentException('Bet not found.');
    }

    private function actor(ServerRequestInterface $request): User
    {
        $actor = $request->getAttribute('user');
        if (!$actor instanceof User) {
            throw new InvalidArgumentException('Authentication required.');
        }

        return $actor;
    }

    /** @param array<string, mixed> $body */
    private function bodyId(array $body, string $key): int
    {
        return $this->positiveId($this->stringValue($body, $key), $key);
    }

    private function positiveId(string $value, string $label): int
    {
        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid %s identifier.', $label));
        }

        return (int)$value;
    }

    /** @param array<string, mixed> $body */
    private function stringValue(array $body, string $key): string
    {
        if (!is_string($body[$key] ?? null)) {
            throw new InvalidArgumentException(sprintf('Invalid %s.', $key));
        }

        return $body[$key];
    }

    /** @param array<string, mixed> $context */
    private function render(ServerRequestInterface $request, ResponseInterface $response, string $template, array $context): ResponseInterface
    {
        $actor = $this->actor($request);
        $context['can_view_bets'] = $this->authorization->can($actor, 'bets.view');
        $context['can_view_contacts'] = $this->authorization->can($actor, 'contacts.view');
        $context['can_view_groups'] = $this->authorization->can($actor, 'groups.view');
        $context['can_view_statistics'] = $this->authorization->can($actor, 'statistics.view');
        $context['can_view_users'] = $this->authorization->can($actor, 'users.view');
        $context['csrf'] = [
            'name_key' => 'csrf_name', 'name' => $request->getAttribute('csrf_name'),
            'value_key' => 'csrf_value', 'value' => $request->getAttribute('csrf_value'),
        ];
        $response->getBody()->write($this->twig->render($template, $context));

        return $response;
    }

    private function redirect(ResponseInterface $response, int $betId): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', sprintf('/bets/%d/stakes?saved=1', $betId));
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