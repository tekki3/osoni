<?php
declare(strict_types=1);

namespace Osoni;

use RuntimeException;

final class Session
{
    public function __construct(private readonly ?DatabaseSessionHandler $handler = null)
    {
    }

    public function start(bool $secure = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if ($this->handler !== null && !session_set_save_handler($this->handler, true)) {
            throw new RuntimeException('Cannot register the database session handler.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('osoni_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Cannot start the session.');
        }
    }

    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
            throw new RuntimeException('Cannot persist the session.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function regenerate(): void
    {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Cannot regenerate the session ID.');
        }
        // PHP carries the token over to the new ID; login and logout rotate it too.
        $this->put('_csrf', bin2hex(random_bytes(32)));
    }

    public function reset(): void
    {
        $_SESSION = [];
        $this->regenerate();
    }

    public function token(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->put('_csrf', $token);
        }
        return $token;
    }

    public function verifyCsrf(Request $request): void
    {
        if ($this->isReadOnly($request->method)) {
            return;
        }
        $token = $request->header('x-csrf-token') ?? $request->input('_token');
        if (!is_string($token) || !hash_equals($this->token(), $token)) {
            throw new HttpException(419, 'Session verification failed. Refresh the page and try again.');
        }
    }

    /**
     * GET/HEAD/OPTIONS carry no token, and a cross-site page cannot send them
     * with a token either. Osoni never changes data in a read handler, so a form
     * can submit these to a state-changing route instead of its POST, which
     * skips the check. Do not treat them as safe in verifyCsrf.
     */
    public function isReadOnly(string $method): bool
    {
        return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
