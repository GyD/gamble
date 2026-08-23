<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Bet\Bet;
use App\Domain\Bet\BetAccessDeniedException;
use App\Domain\Bet\BetStatus;
use App\Domain\User\User;
use App\Repository\BetStore;
use App\Repository\ContactStore;
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
            $bet = $this->accessibleBet($request, $this->positiveId($args['id'] ?? '', 'bet'));
        } catch (BetAccessDeniedException) {
            return $response->withStatus(403);
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        $actor = $this->actor($request);
        $isMutableOwner = $bet->status === BetStatus::Open && $bet->isOwnedBy($actor->id);
        $contacts = array_values(array_filter(
            $this->contacts->findAll(),
            static fn($contact): bool => !$contact->isArchived(),
        ));

        return $this->render($request, $response, 'stakes/index.html.twig', [
            'bet' => $bet,
            'stakes' => $this->stakes->findByBet($bet->id),
            'contacts' => $contacts,
            'can_create' => $isMutableOwner && $this->authorization->can($actor, 'stakes.create'),
            'can_edit' => $isMutableOwner && $this->authorization->can($actor, 'stakes.edit'),
            'can_delete' => $isMutableOwner && $this->authorization->can($actor, 'stakes.delete'),
            'can_refund' => $bet->status === BetStatus::Cancelled
                && $bet->isOwnedBy($actor->id)
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
        } catch (BetAccessDeniedException $exception) {
            return $this->forbidden($response, $exception->getMessage());
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
        } catch (BetAccessDeniedException $exception) {
            return $this->forbidden($response, $exception->getMessage());
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
        } catch (BetAccessDeniedException $exception) {
            return $this->forbidden($response, $exception->getMessage());
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
        } catch (BetAccessDeniedException $exception) {
            return $this->forbidden($response, $exception->getMessage());
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
        } catch (BetAccessDeniedException $exception) {
            return $this->forbidden($response, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response, $betId);
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
        } catch (BetAccessDeniedException $exception) {
            return $this->forbidden($response, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response, $betId);
    }

    private function accessibleBet(ServerRequestInterface $request, int $betId): Bet
    {
        $bet = $this->bets->findById($betId) ?? throw new InvalidArgumentException('Bet not found.');
        $actor = $this->actor($request);
        if (!$bet->isOwnedBy($actor->id) && !$this->authorization->can($actor, 'bets.view_all')) {
            throw new BetAccessDeniedException('Bet access denied.');
        }

        return $bet;
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

    private function forbidden(ResponseInterface $response, string $message): ResponseInterface
    {
        $response->getBody()->write($message);

        return $response->withStatus(403);
    }

    private function ipAddress(ServerRequestInterface $request): ?string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) ? $address : null;
    }
}