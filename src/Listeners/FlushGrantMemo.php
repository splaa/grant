<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Listeners;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Illuminate\Contracts\Container\Container;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Internal\AssignmentMemo;

final readonly class FlushGrantMemo implements EventSubscriber
{
    public function __construct(private Container $container) {}

    /** @return list<string> */
    public function getSubscribedEvents(): array
    {
        return [Events::postPersist, Events::postUpdate, Events::postRemove];
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->forget($args);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->forget($args);
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->forget($args);
    }

    /** @param LifecycleEventArgs<EntityManagerInterface> $args */
    private function forget(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if (! $entity instanceof RoleAssignment) {
            return;
        }

        $change = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity)['userId'] ?? null;

        $memo = $this->container->make(AssignmentMemo::class);

        if (is_array($change) && (is_int($change[0]) || is_string($change[0]))) {
            $memo->forget($change[0]);
        }

        $memo->forget($entity->getUserId());
    }
}
