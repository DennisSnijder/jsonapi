<?php
/**
 * JSONAPI extension for Bolt.
 *
 * @author Tobias Dammers <tobias@twokings.nl>
 * @author Bob den Otter <bob@twokings.nl>
 * @author Xiao-Hu Tai <xiao@twokings.nl>
 */

namespace JSONAPI;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Extension extends \Bolt\BaseExtension
{

    private $base = '/json';
    private $basePath;
    private $paginationNumberKey = 'page'; // todo: page[number]
    private $paginationSizeKey = 'limit';  // todo: page[size]

    public function getName()
    {
        return "JSONAPI";
    }

    //
    // Examples:
    //
    // Basic:
    //     /{contenttype}
    //     /{contenttype}/{id}
    //     /{contenttype}?page={x}&limit={y}
    //     /{contenttype}?include={relationship1,relationship2}
    //     /{contenttype}?fields[{contenttype1}]={field1,field2} -- Note: taxonomies and relationships are fields as well.
    //
    // Relationships:
    //     /{contenttype}/{id}/relationships/{relationship} -- Note: this "relationship" is useful for handling the relationship between two instances.
    //     /{contenttype}/{id}/{relationship} -- Note: this "related resource" is useful for fetching the related data. These are not "self" links.
    //
    // Filters:
    //     /{contenttype}?filter[{contenttype1}]={value1,value2}
    //     /{contenttype}?filter[{field1}]={value1,value2}&filter[{field2}]={value3,value4} -- For Bolt, this seems the most logical (similar to a `where` clause)
    //
    // Search:
    //     /{contenttype}?q={query} -- search within a contenttype
    //     /search/q={query} -- search in all contenttypes
    //
    // sources: http://jsonapi.org/examples/
    //          http://jsonapi.org/recommendations/
    //
    public function initialize()
    {
        if(isset($this->config['base'])) {
            $this->base = $this->config['base'];
        }
        $this->basePath = $this->app['paths']['canonical'] . $this->base;

        $this->app->get($this->base."/search", [$this, 'search'])
                  ->bind('jsonapi_search_mixed');
        $this->app->get($this->base."/{contenttype}/search", [$this, 'search'])
                  ->bind('jsonapi_search');
        $this->app->get($this->base."/{contenttype}/{slug}/{relatedContenttype}", [$this, 'json'])
                  ->value('relatedContenttype', null)
                  ->assert('slug', '[a-zA-Z0-9_\-]+')
                  ->bind('jsonapi');
        $this->app->get($this->base."/{contenttype}", [$this, 'json_list'])
                  ->bind('jsonapi_list');
    }

    public function json_list(Request $request, $contenttype)
    {
        $this->request = $request;

        if (!array_key_exists($contenttype, $this->config['contenttypes'])) {
            return $this->responseNotFound();
        }
        $options = [];
        // if ($limit = $request->get('page')['size']) { // breaks things in src/Storage.php at executeGetContentQueries
        if ($limit = $request->get($this->paginationSizeKey)) {
            $limit = intval($limit);
            if ($limit >= 1) {
                $options['limit'] = $limit;
            }
        }
        // if ($page = $request->get('page')['number']) { // breaks things in src/Storage.php at executeGetContentQueries
        if ($page = $request->get($this->paginationNumberKey)) {
            $page = intval($page);
            if ($page >= 1) {
                $options['page'] = $page;
            }
        }
        if ($order = $request->get('order')) {
            if (!preg_match('/^([a-zA-Z][a-zA-Z0-9_\\-]*)\\s*(ASC|DESC)?$/', $order, $matches)) {
                $this->responseInvalidRequest();
            }
            $options['order'] = $order;
        }

        // Enable pagination
        $options['paging'] = true;
        $pager  = [];
        $where  = [];
        $fields = [];

        // Contenttype fields
        $contenttypeBaseFields = \Bolt\Content::getBaseColumns();
        $contenttypeDefinedFields = array_keys($this->app['config']->get("contenttypes/$contenttype/fields"));
        $contenttypeAllFields = array_merge($contenttypeBaseFields, $contenttypeDefinedFields);
        $contenttypeAllowedFields = isset($this->config['contenttypes'][$contenttype]['allowed-fields']) ? $this->config['contenttypes'][$contenttype]['allowed-fields'] : [];

        // todo: handle "include" / relationships
        // $included = [];
        // if ($include = $request->get('include')) {
        //     $where = [];
        // }

        // Handle $fields[], e.g. $fields['pages'] = [ 'teaser', 'body', 'image']
        // Note: `id` and `type` (`contenttype`) are always required.
        if ($fields = $request->get('fields')) {
            foreach($fields as $key => $value) {
                $fields[$key] = [];
                $values = explode(',', $value);

                // All existing fields are allowed, if no `allowed-fields` is defined.
                if ($contenttypeAllowedFields) {
                    $contenttypeAllowedFields = $contenttypeAllFields;
                }

                // filtered fields need to be allowed and need to exist.
                foreach ($values as $v) {
                    if (in_array($v, $contenttypeAllowedFields)) {
                        $fields[$key][] = $v;
                    }
                }
            }
        }

        if (!isset($fields[$contenttype]) || empty($fields[$contenttype])) {
            $fields[$contenttype] = $this->config['contenttypes'][$contenttype]['list-fields'];
        }

        //
        // how does this work with the fields in defined in config????
        // does a list of allowedfields need to be defined ??
        //
        // every contenttype has `allowed-fields` in the `config.yml`
        // through which the fields get filtered (if it exists), otherwise,
        // use `item-fields` or `list-fields` if they exists
        //

        // Use the `where-clause` defined in the contenttype config.
        if (isset($this->config['contenttypes'][$contenttype]['where-clause'])) {
            $where = $this->config['contenttypes'][$contenttype]['where-clause'];
        }

        // Handle $filter[], this modifies the $where[] clause.
        if ($filters = $request->get('filter')) {
            foreach($filters as $key => $value) {
                if (!in_array($key, $contenttypeAllFields)) {
                    return $this->responseInvalidRequest();
                }
                // A bit crude for now.
                $where[$key] = str_replace(',', ' || ', $value);
            }
        }

        $items = $this->app['storage']->getContent($contenttype, $options, $pager, $where);

        // If we don't have any items, this can mean one of two things: either
        // the content type does not exist (in which case we'll get a non-array
        // response), or it exists, but no content has been added yet.
        if (!is_array($items)) {
            // todo
            throw new \Exception("Configuration error: $contenttype is configured as a JSON end-point, but doesn't exist as a content type.");
        }

        if (empty($items)) {
            $items = [];
        }

        $meta = [
            "count" => count($items),
            "total" => intval($pager['count'])
        ];

        $items = array_values($items);
        $items = array_map([$this, 'clean_list_item'], $items); // todo: relationships are lost

        return $this->response([
            'links' => $this->makeLinks($contenttype, $pager['current'], intval($pager['totalpages']), $limit),
            'meta' => $meta,
            'data' => $items,
            // 'related' => [],
            // 'included' => $included // included related objects
        ]);

    }

    public function json(Request $request, $contenttype, $slug, $relatedContenttype)
    {
        $this->request = $request;

        if (!array_key_exists($contenttype, $this->config['contenttypes'])) {
            return $this->responseNotFound();
        }

        $item = $this->app['storage']->getContent("$contenttype/$slug");
        if (!$item) {
            return $this->responseNotFound();
        }

        // If a related entity name is given, we fetch its content instead
        if ($relatedContenttype !== null)
        {
            $items = $item->related($relatedContenttype);
            if (!$items) {
                return $this->responseNotFound();
            }
            $items = array_map([$this, 'clean_list_item'], $items);
            $response = $this->response([
                'data' => $items
            ]);

        } else {

            $values = $this->clean_full_item($item);
            $prev = $item->previous();
            $next = $item->next();

            $links = [
                'self' => $values['links']['self'],
            ];
            if ($prev)  {
                $links['prev'] = sprintf('%s/%s/%d', $this->basePath, $contenttype, $prev->values['id']);
            }
            if ($next) {
                $links['next'] = sprintf('%s/%s/%d', $this->basePath, $contenttype, $next->values['id']);
            }

            $response = $this->response([
                'links' => $links,
                'data' => $values,
            ]);
        }

        return $response;
    }

    // todo: handle search
    public function search(Request $request, $contenttype = null)
    {
        $this->request = $request;
        // $this->app['storage']
        return $this->responseNotFound();
    }

    private function clean_item($item, $type = 'list-fields')
    {

        $contenttype = $item->contenttype['slug'];
        if (isset($this->config['contenttypes'][$contenttype][$type])) {
            $fields = $this->config['contenttypes'][$contenttype][$type];
        }
        else {
            $fields = array_keys($item->contenttype['fields']);
        }

        // Both 'id' and 'type' are required.
        $values = [
            'id' => $item->values['id'],
            'type' => $contenttype,
            'attributes' => []
        ];
        $fields = array_unique($fields);
        foreach ($fields as $key => $field) {
            $values['attributes'][$field] = $item->values[$field];
        }

        // Check if we have image or file fields present. If so, see if we need to
        // use the full URL's for these.
        foreach($item->contenttype['fields'] as $key => $field) {
            if (($field['type'] == 'image' || $field['type'] == 'file') && isset($values['attributes'][$key])) {
                $values['attributes'][$key]['url'] = sprintf('%s%s%s',
                    $this->app['paths']['canonical'],
                    $this->app['paths']['files'],
                    $values['attributes'][$key]['file']
                    );
            }
            if ($field['type'] == 'image' && isset($values['attributes'][$key]) && is_array($this->config['thumbnail'])) {
                // dump($this->app['paths']);
                $values['attributes'][$key]['thumbnail'] = sprintf('%s/thumbs/%sx%s/%s',
                    $this->app['paths']['canonical'],
                    $this->config['thumbnail']['width'],
                    $this->config['thumbnail']['height'],
                    $values['attributes'][$key]['file']
                    );
            }
        }

        // todo: add "links"
        $values['links'] = [
            'self' => sprintf('%s/%s/%s', $this->basePath, $contenttype, $item->values['id']),
        ];

        // todo: taxonomy
        // todo: tags
        // todo: categories
        // todo: groupings
        if ($item->taxonomy) {
            foreach($item->taxonomy as $key => $value) {
                // $values['attributes']['taxonomy'] = [];
            }
        }

        // todo: "relationships"
        if ($item->relation) {
            $values['relationships'] = [];
        }

        // todo: "meta"
        // todo: "links"

        return $values;

    }

    private function clean_list_item($item)
    {
        return $this->clean_item($item, 'list-fields');
    }

    private function clean_full_item($item)
    {
        return $this->clean_item($item, 'item-fields');
    }

    /**
     * Returns the values for the "links" object in a listing response.
     * Bolt uses the page-based pagination strategy.
     *
     * Recommended pagination strategies, according to jsonapi are:
     * - page-based   : page[number] and page[size]
     * - offset-based : page[offset] and page[limit]
     * - cursor-based : page[cursor]
     *
     * source: http://jsonapi.org/format/#fetching-pagination
     *
     * @param string $contenttype The name of the contenttype.
     * @param int $currentPage The current page number.
     * @param int $totalPages The total number of pages.
     * @param int $pageSize The number of items per page.
     * @return mixed[] An array with URLs for the current page and related pages.
     */
    private function makeLinks($contenttype, $currentPage, $totalPages, $pageSize)
    {

        $basePath = $this->basePath;
        // $totalPages = intval($pager['totalpages']);
        // $currentPage = $pager['current'];
        $prevPage = max($currentPage - 1, 1);
        $nextPage = min($currentPage + 1, $totalPages);
        $firstPage = 1;
        $pagination = $firstPage != $totalPages;
        $paginationNumberQuery = sprintf('?%s', $this->paginationNumberKey);
        $paginationSizeQuery  = sprintf('&%s=%d', $this->paginationSizeKey, $pageSize);
        $paginationQuery = $paginationNumberQuery . '%s' . $paginationSizeQuery;

        $links = [];
        $links["self"] = "$basePath/$contenttype" . ($pagination ? sprintf($paginationQuery, $currentPage) : '');
        if ($currentPage != $firstPage) {
            $links["first"] = "$basePath/$contenttype" . ($pagination ? sprintf($paginationQuery, $firstPage) : '');
        }
        if ($currentPage != $totalPages) {
            $links["last"] = "$basePath/$contenttype" . ($pagination ? sprintf($paginationQuery, $totalPages) : '');
        }
        if ($currentPage != $prevPage) {
            $links["prev"] = "$basePath/$contenttype" . ($pagination ? sprintf($paginationQuery, $prevPage) : '');
        }
        if ($currentPage != $nextPage) {
            $links["next"] = "$basePath/$contenttype" . ($pagination ? sprintf($paginationQuery, $nextPage) : '');
        }

        // todo: use "related" for additional related links.
        // $links["related"]

        return $links;
    }

    /**
     * Respond with a 404 Not Found.
     *
     * @param array $data Optional data to pass on through the response.
     * @return Symfony\Component\HttpFoundation\Response
     */
    private function responseNotFound($data = [])
    {
        return $this->responseError('404', 'Not Found', $data);
    }

    /**
     * Respond with a simple 400 Invalid Request.
     *
     * @param array $data Optional data to pass on through the response.
     * @return Symfony\Component\HttpFoundation\Response
     */
    private function responseInvalidRequest($data = [])
    {
        return $this->responseError('400', 'Invalid Request', $data);
    }

    /**
     * Make a response with an error.
     *
     * @param string $status HTTP status code.
     * @param string $title Human-readable summary of the problem.
     * @param array $data Optional data to pass on through the response.
     * @return Symfony\Component\HttpFoundation\Response
     */
    private function responseError($status, $title, $data = [])
    {
        // todo: filter unnecessary fields.
        // $allowedErrorFields = [ 'id', 'links', 'about', 'status', 'code', 'title', 'detail', 'source', 'meta' ];
        return $this->response($data);
    }

    /**
     * Makes a JSON response, with either data or an error, never both.
     *
     * @param array $array The data to wrap in the response.
     * @return Symfony\Component\HttpFoundation\Response
     */
    private function response($array)
    {

        $json = json_encode($array, JSON_PRETTY_PRINT);
        // $json = json_encode($array, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRETTY_PRINT);

        if (isset($array['errors'])) {
            $status = isset($array['errors']['status']) ? $array['errors']['status'] : 400;
            $response = new Response($json, $status);
        } else {
            $response = new Response($json, 201);
        }

        if (!empty($this->config['headers']) && is_array($this->config['headers'])) {
            // dump($this->config['headers']);
            foreach ($this->config['headers'] as $header => $value) {
                $response->headers->set($header, $value);
            }
        }

        if ($callback = $this->request->get('callback')) {
            $response->setCallback($callback);
        }

        return $response;

    }

}
