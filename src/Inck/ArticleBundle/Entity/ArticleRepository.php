<?php

namespace Inck\ArticleBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * ArticleRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ArticleRepository extends EntityRepository
{
    /**
     * Récupère les articles en fonction des filtres
     *
     * Types disponibles :
     * Brouillons : as_draft
     * Publiés : published
     * En modération : in_moderation
     * En validation (modérés mais non publiés ou approuvés/désapprouvés) : in_validation
     * Désapprouvés : disapproved
     *
     * @param $filters
     * @param int $offset
     * @param int $limit
     * @throws \Exception
     * @return array
     */
    public function findByFilters($filters, $offset = null, $limit = null)
    {
        $query = $this->createQueryBuilder('a');

        // Type d'article
        if(isset($filters['type']))
        {
            switch($filters['type'])
            {
                case 'as_draft':
                    $query
                        ->where('a.asDraft = :asDraft')
                        ->setParameter('asDraft', true)
                        ->andWhere('a.postedAt IS NULL')
                        ->orderBy('a.createdAt', 'DESC');
                    break;

                case 'published':
                    $query
                        ->where('a.published = :published')
                        ->setParameter('published', true)
                        ->orderBy('a.publishedAt', 'DESC');
                    break;

                case 'posted':
                    $query
                        ->where($query->expr()->andx(
                            $query->expr()->isNotNull('a.postedAt')
                        ))
                        ->orderBy('a.postedAt', 'DESC');
                    break;

                case 'in_moderation':
                    $query
                        ->where('a.published = :published')
                        ->setParameter('published', false)
                        ->andWhere('a.asDraft = :asDraft')
                        ->setParameter('asDraft', false)
                        ->andWhere('a.postedAt >= DATE_SUB(CURRENT_TIMESTAMP(), 1, \'DAY\')')
                        ->orderBy('a.postedAt', 'DESC');
                    break;

                case 'in_validation':
                    $query
                        ->where('a.approved IS :approved')
                        ->setParameter('approved', null)
                        ->andWhere('a.asDraft = :asDraft')
                        ->setParameter('asDraft', false)
                        ->andWhere('a.postedAt >= DATE_SUB(CURRENT_TIMESTAMP(), 2, \'DAY\')')
                        ->orderBy('a.postedAt', 'DESC');
                    break;

                case 'disapproved':
                    $query
                        ->where('a.approved = :approved')
                        ->setParameter('approved', false)
                        ->orderBy('a.postedAt', 'DESC');
                    break;

                default:
                    throw new \Exception("Type d'article invalide.");
                    break;
            }
        }

        // Filtres
        if(isset($filters['authors']) && is_array($filters['authors']) && count($filters['authors']) !== 0)
        {
            $query
                ->andWhere('a.author IN :authors')
                ->setParameter('authors', $filters['authors']);
        }

        if(isset($filters['categories']) && is_array($filters['categories']) && count($filters['categories']) !== 0)
        {
            $query
                ->join('a.categories', 'c')
                ->andWhere(
                    $query->expr()->in('c.id', $filters['categories'])
                );
        }

        if(isset($filters['tags']) && is_array($filters['tags']) && count($filters['tags']) !== 0)
        {
            $query
                ->join('a.tags', 't')
                ->andWhere(
                    $query->expr()->in('t.name', $filters['tags'])
                );
        }

        // Offset et limit
        $query
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $articles = $query->getQuery()->getResult();

        // Tri des résultats
        /** @var $article Article */
        foreach($articles as $article)
        {
            $score = 0;

            if(isset($filters['categories']) && is_array($filters['categories']) && count($filters['categories']) !== 0)
            {
                /** @var $category Category */
                foreach($article->getCategories() as $category)
                {
                    foreach($filters['categories'] as $id)
                    {
                        if($category->getId() === (int) $id)
                        {
                            $score += 2;
                            break;
                        }
                    }
                }
            }

            if(isset($filters['tags']) && is_array($filters['tags']) && count($filters['tags']) !== 0)
            {
                /** @var $tag Tag */
                foreach($article->getTags() as $tag)
                {
                    /** @var $filter Tag */
                    foreach($filters['tags'] as $filter)
                    {
                        if($tag->getId() === $filter->getId())
                        {
                            $score++;
                            break;
                        }
                    }
                }
            }

            $article->setSearchScore($score);
        }

        usort($articles, function($a, $b){
            return ($a->getSearchScore() < $b->getSearchScore()) ? 1 : -1;
        });

        return $articles;
    }

    public function countByCategory($category, $published = false)
    {
        $query = $this->createQueryBuilder('a');

        $query->select('COUNT(a)')
            ->join('a.categories', 'c')
            ->where(
                $query->expr()->in('c.id', $category)
            )
            ->andWhere('a.published = :published')
            ->setParameter('published', $published)
        ;

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    public function countByTag($tag, $published = false)
    {
        $query = $this->createQueryBuilder('a');

        $query->select('COUNT(a)')
            ->join('a.tags', 't')
            ->where(
                $query->expr()->in('t.id', $tag)
            )
            ->andWhere('a.published = :published')
            ->setParameter('published', $published)
        ;

        return (int) $query->getQuery()->getSingleScalarResult();
    }
}
