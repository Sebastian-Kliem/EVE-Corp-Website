<?php

namespace App\Repository;

use App\Entity\EveCharacterIndustryJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveCharacterIndustryJob>
 */
class EveCharacterIndustryJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveCharacterIndustryJob::class);
    }
}
