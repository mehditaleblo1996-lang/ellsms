<?php
/**
 * ELLSMS public API — minimal route table + dispatch (Phase 12, STEP 4).
 *
 * Deliberately small: no middleware pipeline, no auto-discovery, just an ordered list of
 * (method, pattern, handler, required scope) tuples matched top-to-bottom. `{name}` path segments
 * become named capture groups. This is the ONE place every /api/v1/* route is declared — see
 * public/api/index.php for where the table is built and dispatched.
 */

declare(strict_types=1);

final class ApiRouter
{
    /** @var array<int, array{method:string,pattern:string,handler:callable,scope:?string}> */
    private array $routes = [];

    public function map(string $method, string $pattern, callable $handler, ?string $requiredScope = null): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $this->compile($pattern),
            'handler' => $handler,
            'scope'   => $requiredScope,
        ];
    }

    private function compile(string $pattern): string
    {
        $escaped = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $escaped . '$#';
    }

    /**
     * Returns one of:
     *   ['matched'=>true,  'handler'=>callable, 'params'=>array<string,string>, 'scope'=>?string]
     *   ['matched'=>false, 'method_not_allowed'=>bool]  — true if some OTHER method matches this path
     *     (so the caller can return 405 instead of a leakier-feeling 404), false for a genuinely
     *     unknown route.
     */
    public function dispatch(string $method, string $path): array
    {
        $method = strtoupper($method);
        $pathMatchedOtherMethod = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $path, $m) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $pathMatchedOtherMethod = true;
                continue;
            }
            $params = array_filter($m, static fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
            return ['matched' => true, 'handler' => $route['handler'], 'params' => $params, 'scope' => $route['scope']];
        }
        return ['matched' => false, 'method_not_allowed' => $pathMatchedOtherMethod];
    }
}
