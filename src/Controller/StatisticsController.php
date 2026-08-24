<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\User\User;
use App\Repository\ContactStore;
use App\Security\AuthorizationService;
use App\Service\StatisticsService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class StatisticsController
{
    public function __construct(
        private StatisticsService    $statistics,
        private ContactStore         $contacts,
        private AuthorizationService $authorization,
        private Environment          $twig,
    )
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = $request->getAttribute('user');
        if (!$actor instanceof User) {
            throw new InvalidArgumentException('Authentication required.');
        }

        $ownerUserId = $this->authorization->can($actor, 'bets.view_all') ? null : $actor->id;
        $response->getBody()->write($this->twig->render('statistics/index.html.twig', [
            'statistics' => $this->statistics->leaderboard(
                $ownerUserId,
                $this->query($request, 'period', StatisticsService::PERIOD_30_DAYS),
                $this->query($request, 'sort', 'win_rate'),
            ),
            'scope_is_global' => $ownerUserId === null,
            'can_view_bets' => $this->authorization->can($actor, 'bets.view'),
            'can_view_contacts' => $this->authorization->can($actor, 'contacts.view'),
            'can_view_groups' => $this->authorization->can($actor, 'groups.view'),
            'can_view_statistics' => true,
            'can_view_users' => $this->authorization->can($actor, 'users.view'),
        ]));

        return $response;
    }

    /** @param array<string, string> $args */
    public function contact(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = $request->getAttribute('user');
        if (!$actor instanceof User) {
            throw new InvalidArgumentException('Authentication required.');
        }
        $value = $args['id'] ?? '';
        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return $response->withStatus(400);
        }
        $contact = $this->contacts->findById((int)$value);
        if ($contact === null) {
            return $response->withStatus(404);
        }
        $ownerUserId = $this->authorization->can($actor, 'bets.view_all') ? null : $actor->id;
        $response->getBody()->write($this->twig->render('statistics/contact.html.twig', [
            'contact' => $contact,
            'statistics' => $this->statistics->contact(
                $contact->id,
                $ownerUserId,
                $this->query($request, 'period', StatisticsService::PERIOD_30_DAYS),
            ),
            'can_view_bets' => $this->authorization->can($actor, 'bets.view'),
            'can_view_contacts' => $this->authorization->can($actor, 'contacts.view'),
            'can_view_groups' => $this->authorization->can($actor, 'groups.view'),
            'can_view_statistics' => true,
            'can_view_users' => $this->authorization->can($actor, 'users.view'),
        ]));

        return $response;
    }

    private function query(ServerRequestInterface $request, string $key, string $default): string
    {
        $value = $request->getQueryParams()[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}