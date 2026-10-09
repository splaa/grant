<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Auth;

use Doctrine\ORM\Mapping as ORM;
use SensitiveParameter;

trait Authenticatable
{
    #[ORM\Column(type: 'string')]
    protected string $password = '';

    #[ORM\Column(name: 'remember_token', type: 'string', nullable: true)]
    protected ?string $rememberToken = null;

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getId();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getRememberToken(): ?string
    {
        return $this->rememberToken;
    }

    public function setRememberToken(#[SensitiveParameter] mixed $value): void
    {
        $this->rememberToken = self::normalizeRememberToken($value);
    }

    public function getRememberTokenName(): string
    {
        return 'rememberToken';
    }

    private static function normalizeRememberToken(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
