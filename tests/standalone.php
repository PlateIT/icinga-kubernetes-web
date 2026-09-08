<?php

namespace Icinga\Exception {
    class ConfigurationError extends \RuntimeException {}
}

namespace Icinga\Application {
    final class TestConfig
    {
        public int $timeout = 15;

        public function get(string $section, string $key, $default = null)
        {
            return $key === 'timeout' ? $this->timeout : $default;
        }
    }

    final class Config
    {
        public static TestConfig $value;

        public static function module(string $name): TestConfig
        {
            return self::$value;
        }
    }
}

namespace Icinga\Authentication {
    final class Auth
    {
        private $user;

        public function __construct($user = null)
        {
            $this->user = $user;
        }

        public static function getInstance(): self
        {
            return new self();
        }

        public function getUser()
        {
            return $this->user;
        }
    }
}

namespace {
    use Icinga\Application\Config;
    use Icinga\Application\TestConfig;
    use Icinga\Authentication\Auth;
    use Icinga\Exception\ConfigurationError;
    use Icinga\Module\Kubernetes\Api\ApiException;
    use Icinga\Module\Kubernetes\Api\Client;
    use Icinga\Module\Kubernetes\Authorization\ResourceAccess;

    final class TestRole
    {
        public function __construct(private ?string $restriction, private bool $unrestricted = false, private bool $grants = true) {}
        public function grants(string $permission): bool { return $this->grants; }
        public function getRestrictions(string $name): ?string { return $this->restriction; }
        public function isUnrestricted(): bool { return $this->unrestricted; }
    }

    final class TestUser
    {
        public function __construct(private array $roles) {}
        public function getRoles(): array { return $this->roles; }
    }

    function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    function expect(string $class, callable $action): void
    {
        try {
            $action();
        } catch (Throwable $error) {
            check($error instanceof $class, "Expected $class, got " . get_class($error));
            return;
        }
        throw new RuntimeException("Expected $class");
    }

    require dirname(__DIR__) . '/library/Kubernetes/Api/ApiException.php';
    require dirname(__DIR__) . '/library/Kubernetes/Api/Client.php';
    require dirname(__DIR__) . '/library/Kubernetes/Authorization/ResourceAccess.php';

    Config::$value = new TestConfig();
    new Client('https://kubernetes-api.example.test', 'reader-token');
    expect(ApiException::class, fn () => new Client('file:///etc/passwd', 'reader-token'));
    expect(ApiException::class, fn () => new Client('https://user@example.test', 'reader-token'));
    expect(ApiException::class, fn () => new Client('https://example.test?token=secret', 'reader-token'));
    Config::$value->timeout = 61;
    expect(ApiException::class, fn () => new Client('https://example.test', 'reader-token'));
    Config::$value->timeout = 15;
    if (! function_exists('curl_init')) {
        $withoutCurl = new Client('https://example.test', 'reader-token');
        expect(ApiException::class, fn () => $withoutCurl->get('status'));
    }

    $access = new ResourceAccess(new Auth(new TestUser([
        new TestRole('cluster=campus&namespace=payments&labels=app%3Dpayments'),
        new TestRole('cluster=primus&namespace=core')
    ])));
    $selectors = $access->selectors();
    check(count($selectors) === 2, 'Granting roles must be OR alternatives');
    check($access->permits(['cluster' => 'campus', 'namespace' => 'payments', 'labels' => ['app' => 'payments']], $selectors), 'Expected resource must be permitted');
    check(! $access->permits(['cluster' => 'campus', 'namespace' => 'payments', 'labels' => ['app' => 'other']], $selectors), 'Label restriction must fail closed');

    $unrestricted = new ResourceAccess(new Auth(new TestUser([new TestRole(null, true)])));
    check($unrestricted->selectors() === [], 'An unrestricted granting role must widen access');
    expect(ConfigurationError::class, fn () => (new ResourceAccess(new Auth(new TestUser([new TestRole('namespace=*')]))))->selectors());
    expect(ConfigurationError::class, fn () => (new ResourceAccess(new Auth(new TestUser([new TestRole('owner=secret')]))))->selectors());
    expect(ConfigurationError::class, fn () => (new ResourceAccess(new Auth(new TestUser([new TestRole('state=healthy')]))))->selectors());
    expect(ConfigurationError::class, fn () => (new ResourceAccess(new Auth(new TestUser([new TestRole('labels=broken')]))))->selectors());
    expect(ConfigurationError::class, fn () => (new ResourceAccess(new Auth(new TestUser([new TestRole('namespace=' . str_repeat('x', 254))]))))->selectors());
    expect(ConfigurationError::class, fn () => (new ResourceAccess(new Auth(new TestUser([new TestRole(null, false, false)]))))->selectors());

    $many = [];
    for ($i = 0; $i < 17; ++$i) {
        $many[] = new TestRole('namespace=n' . $i);
    }
    expect(ConfigurationError::class, fn () => (new ResourceAccess(new Auth(new TestUser($many))))->selectors());

    echo "standalone web security tests: ok\n";
}
