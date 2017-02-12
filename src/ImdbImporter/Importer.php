<?php

namespace ImdbImporter;

use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class Importer implements LoggerAwareInterface
{
    /**
     * @var string|null
     */
    private $id = null;
    /**
     * @var int
     */
    private $rating_base = 10;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $pretend = false;

    /**
     * @param string $id
     * @param int $rating_base
     * @throws Exception
     */
    public function __construct($id, $rating_base = 10)
    {
        $this->id = $id;
        $this->rating_base = $rating_base;
        if ($rating_base <= 0) {
            throw new Exception('Invalid rating base value ' . $rating_base . '. Rating base must be positive.');
        }
    }

    /**
     * Submit ratings to IMDb
     *
     * @param array $ratings
     * e.g.
     * [
     *  [
     *      'title' => 'The Matrix', // Name of the film
     *      'id' => 133093 // If you know it send the imdb ID and that will be used instead of searching
     *      'rating' => 5,
     *  ]
     * ]
     */
    public function submit(array $ratings)
    {
        foreach ($ratings as $rating) {
            if (isset($rating['id'])) {
                $tconst = $this->formatImdbID($rating['id']);
            } else {
                $this->getLogger()->debug("Searching for $rating[title]");
                $tconst = $this->getIdByTitle($rating['title']);
            }

            if ($tconst === null) {
                continue;
            }

            $auth = $this->get_auth_token($tconst);

            $this->submit_rating($rating, $tconst, $auth);
        }
    }

    /**
     * @return \Psr\Log\LoggerInterface
     */
    private function getLogger()
    {
        if ( ! $this->logger) {
            $this->logger = new Logger();
        }

        return $this->logger;
    }

    /**
     * @param \Psr\Log\LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @param bool $value
     * @return $this
     */
    public function setPretend($value)
    {
        $this->pretend = $value;

        return $this;
    }

    /**
     * @param string $title
     * @return null|int
     * @throws Exception
     */
    private function getIdByTitle($title)
    {
        $imdb_title = urlencode($title);

        $content = file_get_contents('http://www.imdb.com/xml/find?json=1&nr=1&tt=on&q=' . $imdb_title);

        if ($content === false) {
            throw new Exception('Error while fetching tconst for ' . $title . '.');
        }

        $json = json_decode($content, true);

        if ($json === null) {
            throw new Exception('Could not decode json result for ' . $title . '.');
        }

        // title_popular, title_exact, title_approx
        // TODO: If we fail to find the exact title, try the next category until we've gone through them all
        if (array_key_exists('title_popular', $json)) {
            $type = 'title_popular';
        } else if (array_key_exists('title_exact', $json)) {
            $type = 'title_exact';
        } else if (array_key_exists('title_approx', $json)) {
            $type = 'title_approx';
        } else {
            $this->getLogger()->warning('Could not find title "' . $title . '"');
            return null;
        }

        // Check title matches
        $matched_title_index = -1;
        $i = -1;
        foreach ($json[$type] as $movie) {
            ++$i;
            if (strcasecmp($movie['title'], $title) === 0) {
                $matched_title_index = $i;
                break;
            }
        }

        if ($matched_title_index === -1) {
            $this->getLogger()->warning('Could not find title "' . $title . '"');
            return null;
        }

        return $json[$type][$matched_title_index]['id'];
    }

    /**
     * @param string $tconst
     * @return string
     */
    private function get_auth_token($tconst)
    {
        $cookie_details = ['id' => $this->id];

        $context_options = [
            'http' => [
                'method' => 'GET',
                'header' => 'Cookie: ' . $this->http_build_cookie($cookie_details)
            ]
        ];
        $context = stream_context_create($context_options);

        $page_url = 'http://www.imdb.com/title/' . $tconst;
        $page_content = file_get_contents($page_url, false, $context);

        $data_auth_begin = strpos($page_content, 'data-auth');
        $data_auth_end = strpos($page_content, '"', $data_auth_begin + 11);
        $data_auth = substr($page_content, $data_auth_begin + 11, $data_auth_end - ($data_auth_begin + 11));

        if ( ! $data_auth) {
            throw new Exception('Could not fetch data auth token.');
        }

        return $data_auth;
    }

    /**
     * @param array $rating
     * @param string $tconst
     * @param $auth
     */
    private function submit_rating(array $rating, $tconst, $auth)
    {
        $this->getLogger()->debug("Submitting rating for " . json_encode($rating) . " $tconst");

        if ( ! $this->pretend) {
            $cookie_details = ['id' => $this->id];

            $imdb_rating = floor($rating['rating'] / $this->rating_base * 10);

            $data = [
                'tconst'       => $tconst,
                'rating'       => $imdb_rating,
                'auth'         => $auth,
                'tracking_tag' => 'title-maindetails'
            ];
            $data = http_build_query($data);
            $context_options = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => 'Cookie: ' . $this->http_build_cookie($cookie_details) . "\r\n" .
                        'Content-type: application/x-www-form-urlencoded' . "\r\n" .
                        'Content-Length: ' . strlen($data),
                    'content' => $data
                ]
            ];
            $context = stream_context_create($context_options);

            $page_url = 'http://www.imdb.com/ratings/_ajax/title';
            $page_content = file_get_contents($page_url, false, $context);
            $page_content = json_decode($page_content, true);

            if ($page_content['status'] !== 200) {
                $this->getLogger()->error('Could not submit rating for ' . json_encode($rating) . ' status = '. $page_content['status'] . '.');
                return;
            }
        }

        $this->getLogger()->info('Submitted rating for ' . (isset($rating['title']) ? $rating['title'] : $rating['id']));
    }

    /**
     * @param array $data
     * @return string
     */
    private function http_build_cookie(array $data)
    {
        $cookie_string = '';
        foreach ($data as $key => $value) {
            $cookie_string .= $key . '=' . $value . ';';
        }
        return $cookie_string;
    }

    /**
     * Turn a numerical id into a tt******* formatted string if required
     *
     * @param string $id
     * @return string
     * @throws \Exception
     */
    private function formatImdbID($id)
    {
        if (strpos($id, 'tt') === 0) {
            return $id;
        }

        if (is_numeric($id)) {
            return 'tt' . str_pad($id, 7, '0', STR_PAD_LEFT);
        }

        throw new Exception("Failed to validate [$id] as an IMDb ID");
    }
}
