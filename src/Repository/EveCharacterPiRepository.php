<?php

namespace App\Repository;

use App\Entity\EveCharacterPi;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveCharacterPi>
 *
 * @method EveCharacterPi|null find($id, $lockMode = null, $lockVersion = null)
 * @method EveCharacterPi|null findOneBy(array $criteria, array $orderBy = null)
 * @method EveCharacterPi[]    findAll()
 * @method EveCharacterPi[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EveCharacterPiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveCharacterPi::class);
    }
}
