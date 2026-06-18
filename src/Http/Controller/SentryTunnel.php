<?php

namespace SentryTunnel\Http\Controller;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

use Safe\Exceptions\UrlException;
use Throwable;

use function Safe\json_decode;
use function Safe\parse_url;

/**
 * @psalm-suppress UnusedClass
 */
class SentryTunnel extends Controller
{
    /**
     * Tunnel the request to Sentry.
     */
    public function tunnel(Request $request): Response
    {
        try {
            $envelope = $request->getContent();
            $pieces = explode("\n", $envelope, 2);
            $header = json_decode($pieces[0], true, flags: JSON_THROW_ON_ERROR);

            [$user, $host, $projectId] = $this->parseDsn($header);

            $this->checkProjectId($projectId);

            Http::withBody($envelope, 'application/x-sentry-envelope')
                ->connectTimeout(2)
                ->timeout(30)
                ->post("https://$host/api/$projectId/envelope/?sentry_key=$user")
                ->throw();
        } catch (Throwable $e) {
            report($e);
        } finally {
            return response(null, 204);
        }
    }

    /**
     * parse the dsn will all controls.
     *
     * @throws UrlException
     */
    private function parseDsn(mixed $header): array
    {
        abort_if(($dsn = data_get($header, 'dsn')) === null, 422, 'no dsn');

        $parts = parse_url($dsn);

        abort_if(($user = $parts['user'] ?? null) === null, 401, 'no user');
        abort_if(($host = $parts['host'] ?? null) === null, 401, 'no host');
        abort_if(!in_array($host, $this->allowedHosts(), true), 401, 'invalid host');

        $path = trim($parts['path'] ?? '', '/');
        abort_if(($projectId = intval($path)) === 0, 422, 'no project');

        return [$user, $host, $projectId];
    }

    /**
     * Get the allowed hosts.
     */
    private function allowedHosts(): array
    {
        return array_filter(Arr::flatten([config('sentry-tunnel.allowed-hosts', [])])); // @phpstan-ignore arrayFilter.strict
    }

    /**
     * Get the allowed projects.
     */
    private function allowedProjects(): array
    {
        $projects = array_filter(Arr::flatten([config('sentry-tunnel.allowed-projects', [])])); // @phpstan-ignore arrayFilter.strict

        return array_map('intval', $projects);
    }

    /**
     * Check the projectId.
     */
    private function checkProjectId(int $projectId): void
    {
        $allowedProjects = $this->allowedProjects();

        abort_if(count($allowedProjects) > 0 && !in_array($projectId, $allowedProjects, true), 401, 'invalid project');
    }
}
