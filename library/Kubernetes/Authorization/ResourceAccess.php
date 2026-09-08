<?php

namespace Icinga\Module\Kubernetes\Authorization;

use Icinga\Authentication\Auth;
use Icinga\Exception\ConfigurationError;

/**
 * Turns role restrictions into API-native, exact resource selectors.
 *
 * Each granting role is an alternative (OR), while predicates inside one
 * restriction are combined (AND). This preserves Icinga Web's role widening
 * semantics without ever handing an unrestricted API response to the browser.
 */
class ResourceAccess
{
    private const MAX_ROLE_SELECTORS = 16;

    public const PERMISSION = 'kubernetes/resources/show';
    public const RESTRICTION = 'kubernetes/filter/resources';

    private Auth $auth;

    public function __construct(?Auth $auth = null)
    {
        $this->auth = $auth ?? Auth::getInstance();
    }

    /** @return array<int, array<string, string>> Empty means unrestricted. */
    public function selectors(): array
    {
        $selectors = [];
        foreach ($this->auth->getUser()->getRoles() as $role) {
            if (! $role->grants(self::PERMISSION)) {
                continue;
            }

            $restriction = $role->getRestrictions(self::RESTRICTION);
            if ($role->isUnrestricted() || $restriction === null || trim($restriction) === '') {
                return [];
            }
            $selector = $this->parse($restriction);
            ksort($selector);
            $selectors[json_encode($selector, JSON_THROW_ON_ERROR)] = $selector;
            if (count($selectors) > self::MAX_ROLE_SELECTORS) {
                throw new ConfigurationError(sprintf(
                    'At most %d distinct Kubernetes role restrictions are supported',
                    self::MAX_ROLE_SELECTORS
                ));
            }
        }

        if ($selectors === []) {
            throw new ConfigurationError('No granting Kubernetes role is available');
        }

        return array_values($selectors);
    }

    /** @return array<string, string> */
    private function parse(string $restriction): array
    {
        parse_str($restriction, $values);
        $allowed = ['cluster', 'group', 'version', 'kind', 'namespace', 'name', 'state', 'labels'];
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_string($value) || ! in_array($key, $allowed, true)) {
                throw new ConfigurationError(sprintf(
                    'Unsupported %s expression %s; only exact API selectors are allowed',
                    self::RESTRICTION,
                    $key
                ));
            }
            if ($value === '' || strpbrk($value, '*?!()|') !== false) {
                throw new ConfigurationError(sprintf(
                    'Unsupported non-exact %s value for %s',
                    self::RESTRICTION,
                    $key
                ));
            }
            $maximums = ['cluster' => 253, 'group' => 512, 'version' => 512, 'kind' => 128,
                'namespace' => 253, 'name' => 512, 'state' => 8, 'labels' => 4096];
            if (strlen($value) > $maximums[$key] || str_contains($value, "\0")) {
                throw new ConfigurationError(sprintf('Invalid %s value for %s', self::RESTRICTION, $key));
            }
            if ($key === 'state' && ! in_array($value, ['ok', 'warning', 'critical', 'unknown'], true)) {
                throw new ConfigurationError(sprintf('Invalid %s state', self::RESTRICTION));
            }
            if ($key === 'labels') {
                $this->labels($value);
            }
            $result[$key] = $value;
        }
        if ($result === []) {
            throw new ConfigurationError(sprintf('%s must not be empty', self::RESTRICTION));
        }

        return $result;
    }

    /** @param array<string, mixed> $resource */
    public function permits(array $resource, array $selectors): bool
    {
        if ($selectors === []) {
            return true;
        }
        foreach ($selectors as $selector) {
            if ($this->matches($resource, $selector)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $resource @param array<string, string> $selector */
    private function matches(array $resource, array $selector): bool
    {
        foreach ($selector as $key => $expected) {
            if ($key === 'labels') {
                foreach ($this->labels($expected) as $name => $value) {
                    if (($resource['labels'][$name] ?? null) !== $value) {
                        return false;
                    }
                }
            } elseif ((string) ($resource[$key] ?? '') !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function labels(string $labels): array
    {
        $result = [];
        foreach (explode(',', $labels) as $label) {
            $parts = explode('=', $label, 2);
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new ConfigurationError('Restricted labels must use key=value[,key=value]');
            }
            $result[$parts[0]] = $parts[1];
        }

        return $result;
    }
}
