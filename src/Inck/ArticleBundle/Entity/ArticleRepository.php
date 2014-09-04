<?php

namespace Inck\ArticleBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Inck\UserBundle\Entity\User;
use Inck\UserBundle\Entity\UserRepository;

/**
 * ArticleRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ArticleRepository extends EntityRepository
{
    const ARTICLES_PER_PAGE = 5;

    /**
     * Récupère les articles en fonction des filtres
     *
     * as_draft         : brouillons
     * published        : publiés
     * posted           : postés
     * in_moderation    : en modération
     * in_validation    : en validation (modérés mais non publiés ou approuvés/désapprouvés)
     * disapproved      : désapprouvés
     *
     * @param $filters
     * @param int|boolean $page
     * @throws \Exception
     * @return array
     */
    public function findByFilters($filters, $page = 1)
    {
        $this->convertFilters($filters);
        $this->checkFilters($filters);

        $qb = $this->createQueryBuilder('a');
        $orderBy = 'postedAt';

        // Type d'article
        if(isset($filters['type']))
        {
            switch($filters['type'])
            {
                case 'as_draft':
                    $qb
                        ->where('a.asDraft = :asDraft')
                        ->setParameter('asDraft', true)
                        ->andWhere('a.postedAt IS NULL');

                    $orderBy = 'createdAt';
                    break;

                case 'published':
                    $qb
                        ->where('a.published = :published')
                        ->setParameter('published', true);

                    $orderBy = 'publishedAt';
                    break;

                case 'posted':
                    $qb
                        ->where(
                            $qb
                                ->expr()
                                ->isNotNull('a.postedAt')
                        );
                    break;

                case 'in_moderation':
                    $qb
                        ->where('a.published = :published')
                        ->setParameter('published', false)
                        ->andWhere('a.asDraft = :asDraft')
                        ->setParameter('asDraft', false)
                        ->andWhere('a.postedAt >= DATE_SUB(CURRENT_TIMESTAMP(), 1, \'DAY\')');
                    break;

                case 'in_validation':
                    $qb
                        ->where('a.approved IS :approved')
                        ->setParameter('approved', null)
                        ->andWhere('a.asDraft = :asDraft')
                        ->setParameter('asDraft', false)
                        ->andWhere('a.postedAt >= DATE_SUB(CURRENT_TIMESTAMP(), 2, \'DAY\')');
                    break;

                case 'disapproved':
                    $qb
                        ->where('a.approved = :approved')
                        ->setParameter('approved', false);

                    $orderBy = 'createdAt';
                    break;

                default:
                    throw new \Exception("Type d'article invalide.");
                    break;
            }
        }

        // Création des conditions pour les filtres
        $orX = $qb->expr()->orX();

        $conditions = array(
            'authors'       => array(
                'field' => 'author',
                'table' => 'InckUserBundle:User',
                'alias' => 'u',
            ),
            'categories'    => array(
                'field' => 'categories',
                'table' => 'InckArticleBundle:Category',
                'alias' => 'c',
            ),
            'tags'          => array(
                'field' => 'tags',
                'table' => 'InckArticleBundle:Tag',
                'alias' => 't',
            ),
        );

        foreach($conditions as $filter => $parameters)
        {
            /**
             * @var string $field
             * @var string $table
             * @var string $alias
             */
            extract($parameters);

            if(isset($filters[$filter]))
            {
                $filterName = $field.'Filter';
                $columnName = $alias.'Score';

                $orX->add(
                    $qb
                        ->expr()
                        ->in("$field.id", ":$filterName")
                );

                /** @var CategoryRepository|TagRepository|UserRepository $repository */
                $repository = $this
                    ->getEntityManager()
                    ->getRepository($table);

                $qb
                    ->innerJoin("a.$field", $field)
                    ->addSelect(
                        $repository->getScoreFilterQuery($filterName, $columnName)
                    )
                    ->setParameter($filterName, $filters[$filter])
                    ->addOrderBy($columnName, 'DESC')
                ;
            }
        }

        $qb
            ->andWhere($orX)
            ->groupBy('a.id')
            ->addOrderBy("a.$orderBy", 'DESC');

        // Filtre "search"
        if(isset($filters['search']))
        {
            $orX = $qb->expr()->orX();
            $fields = array('title', 'summary', 'content');

            foreach($fields as $field)
            {
                $orX->add(
                    $qb
                        ->expr()
                        ->like("a.$field", ':search')
                );
            }

            $fields = array(
                'categories'    => array(
                    'name',
                ),
                'tags'          => array(
                    'name',
                ),
                'author'        => array(
                    'username',
                    'firstname',
                    'lastname',
                ),
            );

            foreach($fields as $field => $columnNames)
            {
                $qb->innerJoin("a.$field", $field);

                foreach($columnNames as $columnName)
                {
                    $orX->add(
                        $qb
                            ->expr()
                            ->like("$field.$columnName", ':search')
                    );
                }
            }

            $qb
                ->andWhere($orX)
                ->setParameter('search', '%'.$filters['search'].'%');
        }

        // Retourner les résultats d'une page
        if($page !== false)
        {
            $paginator = new Paginator($qb);

            $totalArticles = count($paginator);
            $totalPages = ceil($totalArticles / self::ARTICLES_PER_PAGE);

            $paginator
                ->getQuery()
                ->setFirstResult(($page - 1) * self::ARTICLES_PER_PAGE)
                ->setMaxResults(self::ARTICLES_PER_PAGE);

            $results = $paginator
                ->getQuery()
                ->getResult();

            $this->formatResults($results);

            return array($results, $totalArticles, $totalPages);
        }

        // Retourner tous les résultats
        else
        {
            $results = $qb->getQuery()->getResult();
            $this->formatResults($results);
            return $results;
        }
    }

    /**
     * @param int $category
     * @param bool $published
     * @return int
     */
    public function countByCategory($category, $published = false)
    {
        $qb = $this->createQueryBuilder('a');

        $qb->select('COUNT(a)')
            ->join('a.categories', 'c')
            ->where($qb
                ->expr()
                ->in('c.id', $category)
            )
            ->andWhere('a.published = :published')
            ->setParameter('published', $published)
        ;

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param int $tag
     * @param bool $published
     * @return int
     */
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

    /**
     * Convertit les filtres reçus
     * @param $filters array
     */
    private function convertFilters(&$filters)
    {
        // Conversion "one to many"
        $conversion = array(
            'author'    => 'authors',
            'category'  => 'categories',
            'tag'       => 'tags',
        );

        foreach($conversion as $from => $to)
        {
            if(isset($filters[$from]))
            {
                /** @var Category|Tag|User $entity */
                $entity = $filters[$from];

                if(isset($filters[$to]))
                {
                    $filters[$to][] = $entity->getId();
                }

                else
                {
                    $filters[$to] = array($entity->getId());
                }

                unset($filters[$from]);
            }
        }

        // Conversion "string to array"
        $conversion = array_values($conversion);

        foreach($conversion as $to)
        {
            if(isset($filters[$to]) && is_string($filters[$to]))
            {
                $filters[$to] = explode(',', $filters[$to]);
            }
        }
    }

    /**
     * Vérifie les filtres reçus
     * @param $filters mixed
     * @throws \Exception
     */
    private function checkFilters(&$filters)
    {
        if(!is_array($filters) || count($filters) === 0)
        {
            throw new \Exception("Filtres invalides");
        }

        $validTypes = array(
            'as_draft',
            'published',
            'posted',
            'in_moderation',
            'in_validation',
            'disapproved',
        );

        foreach($filters as $filter => $data)
        {
            switch($filter)
            {
                case 'type':
                    if(!in_array($data, $validTypes))
                    {
                        throw new \Exception("Type $filter invalide");
                    }
                    break;

                case 'authors':
                case 'categories':
                case 'tags':
                    if(!is_array($data))
                    {
                        throw new \Exception("Filtre $filter invalide");
                    }
                    break;

                case 'search':
                    if(!is_string($data))
                    {
                        throw new \Exception("Filtre $filter invalide");
                    }
                    break;

                default:
                    throw new \Exception("Filtre $filter non géré");
                    break;
            }
        }
    }

    /**
     * @param array $results
     */
    private function formatResults(&$results)
    {
        foreach($results as &$result)
        {
            if(is_array($result) && isset($result[0]))
            {
                $result = $result[0];
            }
        }
    }
}
