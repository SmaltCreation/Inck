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
    const ARTICLES_PER_PAGE = 10;

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
     * @param boolean $limit
     * @throws \Exception
     * @return array
     */
    public function findByFilters($filters, $page = 1, $limit = false)
    {
        $this->convertFilters($filters);
        $this->checkFilters($filters);

        $qb = $this->createQueryBuilder('a');
        $orderBy = 'postedAt';

        // Type d'article : si on reçoit le filtre "type", on adapte la requête en fonction du type
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

        // Préparation des paramètres dans le cas où on reçoit un filtre sur les auteurs, les catégories ou les tags
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

            // Si on reçoit le filtre "authors", "categories" ou "tags"
            if(isset($filters[$filter]))
            {
                $filterName = $field.'Filter';
                $columnName = $alias.'Score';

                // On ajoute un "WHERE x IN (y)" à la requête, par exemple "WHERE author.id IN (1, 2, 3)"
                $orX->add(
                    $qb
                        ->expr()
                        ->in("$field.id", ":$filterName")
                );

                // Pour calculer le score, on récupère le bon repository, en fonction du filtre traité
                /** @var CategoryRepository|TagRepository|UserRepository $repository */
                $repository = $this
                    ->getEntityManager()
                    ->getRepository($table);

                // On effectue une jointure afin de calculer le score de l'article, par exemple pour le filtre "authors" :
                // On ajoute une colonne "authorScore". La valeur est calculée par la méthode "getScoreFilterQuery" du repository
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
            ->groupBy('a.id')
            ->andWhere($orX);

        // Filtre "not" (articles similaires) : ce filtre est principalement utilisé pour récupérer les articles similaires à un article,
        // et permet de ne pas retourner l'article dont on cherche les articles similaires
        if(isset($filters['not']))
        {
            $qb
                ->andWhere('a.id != :not')
                ->setParameter('not', $filters['not']);
        }

        // Ordre : tri des articles par nombre de votes positifs décroissant, et par nombre de votes négatifs croissant
        if(isset($filters['order']) && $filters['order'] === 'vote')
        {
            /** @var VoteRepository $repository */
            $repository = $this
                ->getEntityManager()
                ->getRepository('InckArticleBundle:Vote');

            $qb
                ->innerJoin('a.votes', 'votes')
                ->addSelect(
                    $repository->getOrderFilterQuery('votesUpFilter', 'vUpOrder', 'vUp')
                )
                ->setParameter('votesUpFilter', true)
                ->addOrderBy('vUpOrder', 'DESC')
                ->addSelect(
                    $repository->getOrderFilterQuery('votesDownFilter', 'vDownOrder', 'vDown')
                )
                ->setParameter('votesDownFilter', false)
                ->addOrderBy('vDownOrder', 'ASC')
            ;
        }

        // Les articles sont toujours triés par date décroissante
        $qb->addOrderBy("a.$orderBy", 'DESC');

        // Filtre "search" : on recherche le terme envoyé dans les champs "title", "summary" et "content" des articles
        // ainsi que dans les noms des catégories, des tags et des auteurs
        if(isset($filters['search']))
        {
            $orX = $qb->expr()->orX();
            $fields = array('title', 'summary', 'content');

            // Ajout de la condition "WHERE x LIKE 'y'" (par exemple "WHERE title LIKE '%test%'
            foreach($fields as $field)
            {
                $orX->add(
                    $qb
                        ->expr()
                        ->like("a.$field", ':search')
                );
            }

            // Pour les auteurs, on cherche dans le pseudo, le prénom et le nom
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

            // Création des jointures pour tous les champs sur les catégories, les tags et les auteurs
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

            // Complétion de la requête : ajout du terme recherché
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

        // Retourner un certain nombre de résultats
        if($limit !== false)
        {
            $qb->setMaxResults($limit);
        }

        // Retourner les résultats
        $results = $qb->getQuery()->getResult();
        $this->formatResults($results);

        return $results;
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
     * @param int $category
     * @param bool $published
     * @return int
     */
    public function getLastOfCategory($category, $published = false)
    {
        $qb = $this->createQueryBuilder('a');

        $qb->select('a')
            ->join('a.categories', 'c')
            ->where($qb
                ->expr()
                ->in('c.id', $category)
            )
            ->andWhere('a.published = :published')
            ->setParameter('published', $published)
            ->orderBy('a.publishedAt', 'DESC')
        ;

        return $qb->setMaxResults(1)->getQuery()->getResult();
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
        // Conversion "one to many" : si on reçoit par exemple un filtre "author",
        // on le convertit en un filtre "authors"
        $conversion = array(
            'author'    => 'authors',
            'category'  => 'categories',
            'tag'       => 'tags',
        );

        foreach($conversion as $from => $to)
        {
            if(isset($filters[$from]))
            {
                // On utilise seulement l'id de l'entité
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

        // Conversion "string to array" : si on reçoit par exemple un filtre "authors" qui contient une chaîne,
        // on la convertit en tableau
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

        // On vérifie chaque filtre, et les données reçues
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
                // Si on reçoit un filtre par "type", on regarde si type reçu est valide
                case 'type':
                    if(!in_array($data, $validTypes))
                    {
                        throw new \Exception("Type $filter invalide");
                    }
                    break;

                // Si on reçoit un filtre sur les auteurs, les catégories ou les tags, on a besoin d'un tableau
                case 'authors':
                case 'categories':
                case 'tags':
                    if(!is_array($data))
                    {
                        throw new \Exception("Filtre $filter invalide");
                    }
                    break;

                // Si on reçoit le filtre "search" ou "order", on a besoin d'une chaîne
                case 'search':
                case 'order':
                    if(!is_string($data))
                    {
                        throw new \Exception("Filtre $filter invalide");
                    }
                    break;

                // Si on reçoit le filtre "not", on a besoin d'un id
                case 'not':
                    if(!is_int($data))
                    {
                        throw new \Exception("Filtre $filter invalide");
                    }
                    break;

                // Si on reçoit autre chose, on lance une exception
                default:
                    throw new \Exception("Filtre $filter non géré");
                    break;
            }
        }
    }

    /**
     * Permet de ne revoyer que les objets (sans les colonnes ajoutées pour calculer un score de recherche)
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
