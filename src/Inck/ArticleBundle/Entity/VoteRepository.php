<?php

namespace Inck\ArticleBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * VoteRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class VoteRepository extends EntityRepository
{
    public function countByArticle($article, $up)
    {
        $query = $this
            ->createQueryBuilder('v')
            ->select('COUNT(v)')
            ->where('v.article = :article')
            ->setParameter('article', $article)
            ->andWhere('v.up = :up')
            ->setParameter('up', $up)
            ->getQuery()
        ;

        return (int) $query->getSingleScalarResult();
    }
}
