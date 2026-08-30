<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Bet\Bet;
use App\Domain\User\User;
use App\Repository\BetStore;
use App\Security\AuthorizationService;
use App\Service\BetService;
use App\Service\StatisticsService;
use App\Service\StakeService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class BetController
{
    public function __construct(
        private BetStore $bets,
        private BetService $service,
        private AuthorizationService $authorization,
        private Environment $twig,
        private StatisticsService $statistics,
        private StakeService $stakes,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = $this->actor($request);
        $bets = array_map($this->service->withOdds(...), $this->bets->findAll());

        return $this->render($request, $response, 'bets/index.html.twig', [
            'bets' => $bets,
            'can_view_stakes' => $this->authorization->can($actor, 'stakes.view'),
            'can_create' => $this->authorization->can($actor, 'bets.create'),
            'can_edit' => $this->authorization->can($actor, 'bets.edit'),
            'can_cancel' => $this->authorization->can($actor, 'bets.delete'),
            'can_close' => $this->authorization->can($actor, 'bets.close'),
            'can_settle' => $this->authorization->can($actor, 'bets.settle'),
            'saved' => isset($request->getQueryParams()['saved']),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $body = (array) $request->getParsedBody();
            $this->service->create(
                $this->actor($request)->id,
                $this->stringValue($body, 'question'),
                $this->nullableStringValue($body, 'description'),
                $this->nullableStringValue($body, 'closes_at'),
                $this->stringList($body, 'options'),
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $bet = $this->bet($args);
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }
        if ($bet === null) {
            return $response->withStatus(404);
        }
        $bet = $this->service->withOdds($bet);

        $actor = $this->actor($request);

        return $this->render($request, $response, 'bets/show.html.twig', [
            'bet' => $bet,
            'can_edit' => $this->authorization->can($actor, 'bets.edit'),
            'can_cancel' => $this->authorization->can($actor, 'bets.delete'),
            'can_close' => $this->authorization->can($actor, 'bets.close'),
            'can_settle' => $this->authorization->can($actor, 'bets.settle'),
            'can_view_stakes' => $this->authorization->can($actor, 'stakes.view'),
            'statistics' => $this->statistics->bet($bet->id),
            'winners' => $this->stakes->winnings($bet),
            'can_manage_winnings' => $this->authorization->can($actor, 'stakes.edit'),
        ]);
    }

    /** @param array<string, string> $args */
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $bet = $this->bet($args);
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }
        if ($bet === null) {
            return $response->withStatus(404);
        }

        return $this->render($request, $response, 'bets/edit.html.twig', [
            'bet' => $bet,
            'has_stakes' => $this->service->hasStakes($bet->id),
        ]);
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $betId = $this->existingBetId($args);
            $body = (array) $request->getParsedBody();
            $this->service->update(
                $this->actor($request)->id,
                $betId,
                $this->stringValue($body, 'question'),
                $this->nullableStringValue($body, 'description'),
                $this->nullableStringValue($body, 'closes_at'),
                $this->stringList($body, 'options'),
                $this->ipAddress($request),
                $this->nullableStringValue($body, 'bookmaker_percentage'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function close(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->runTransition($request, $response, $args, 'close');
    }

    /** @param array<string, string> $args */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->runTransition($request, $response, $args, 'cancel');
    }

    /** @param array<string, string> $args */
    public function settle(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $betId = $this->existingBetId($args);
            $body = (array) $request->getParsedBody();
            $this->service->settle(
                $this->actor($request)->id,
                $betId,
                $this->positiveId($this->stringValue($body, 'winning_option_id')),
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->service->delete(
                $this->actor($request)->id,
                $this->existingBetId($args),
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    private function runTransition(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
        string $transition,
    ): ResponseInterface {
        try {
            $betId = $this->existingBetId($args);
            $this->service->{$transition}(
                $this->actor($request)->id,
                $betId,
                $this->ipAddress($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($response, $exception->getMessage());
        }

        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    private function bet(array $args): ?Bet
    {
        return $this->bets->findById($this->positiveId($args['id'] ?? ''));
    }

    /** @param array<string, string> $args */
    private function existingBetId(array $args): int
    {
        $id = $this->positiveId($args['id'] ?? '');
        if ($this->bets->findById($id) === null) {
            throw new InvalidArgumentException('Bet not found.');
        }

        return $id;
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
            throw new InvalidArgumentException('Invalid bet identifier.');
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

    /** @param array<string, mixed> $body @return list<string> */
    private function stringList(array $body, string $key): array
    {
        $values = $body[$key] ?? null;
        if (!is_array($values)) {
            throw new InvalidArgumentException(sprintf('Invalid %s.', $key));
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf('Invalid %s.', $key));
            }
        }

        return array_values($values);
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

    private function redirect(ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', '/bets?saved=1');
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