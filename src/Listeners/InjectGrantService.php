<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Listeners;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Illuminate\Contracts\Container\Container;
use Shipfastlabs\Grant\Grant;

final readonly class InjectGrantService implements EventSubscriber
{
    public function __construct(private Container $container) {}

    /** @return list<string> */
    public function getSubscribedEvents(): array
    {
        return [Events::postLoad, Events::postPersist];
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $this->inject($args);
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->inject($args);
    }

    /** @param LifecycleEventArgs<EntityManagerInterface> $args */
    private function inject(LifecycleEventArgs $args): void
    {
        $injector = [$args->getObject(), 'injectGrantService'];

        if (is_callable($injector)) {
            $injector($this->container->make(Grant::class));
        }
    }
}
