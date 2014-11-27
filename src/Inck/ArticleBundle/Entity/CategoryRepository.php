<?php

namespace Inck\ArticleBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;

/**
 * CategoryRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class CategoryRepository extends EntityRepository
{
    /**
     * @param string $filterName
     * @param string $columnName
     * @return string
     */
    public function getScoreFilterQuery($filterName, $columnName)
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->innerJoin('c.articles', 'ca')
            ->where('ca.id = a.id')
            ->andWhere("c.id IN (:$filterName)");

        return sprintf('(%s) AS %s', $qb->getDQL(), $columnName);
    }

    /**
     * @return int
     */
    public function countAll()
    {
        $qb = $this->createQueryBuilder('c')->select('COUNT(c)');
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array
     */
    public function getPopular()
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c')
            ->leftJoin('c.articles', 'a', Join::WITH, 'a.published = :published')
            ->addSelect('COUNT(a) AS HIDDEN nArticles')
            ->setParameter('published', true)
            ->groupBy('c.id')
            ->orderBy("nArticles", 'DESC')
        ;

        return $qb->getQuery()->getResult();
    }
}
