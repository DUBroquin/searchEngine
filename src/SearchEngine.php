<?php

namespace Dbroquin\SearchEngine;

use Illuminate\Console\DetectsApplicationNamespace;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Search engine for Vuetable 2
 */
class SearchEngine{

    use DetectsApplicationNamespace;

    protected $_request;
    protected $_relationships;
    protected $_separator;

    // Constuctor
    public function __construct($request, $relationships = []){
        $this->_request = $request;
        $this->_relationships = $relationships;
    }

    /**
     * Create function from query 
     * with model contain in $_request
     *
     * @return object
     */
    public function makeWithQuery($query = null)
    {
        if ($query == null) {
            $model = $this->getModel();
            $query = $model->with($this->_relationships);
            
            return $this->engine($query);
        }else{
            return $this->engine($query);
        }
    }

    /**
     * Create datatable from gived collection
     *
     * @param mixed[] $collection Collection
     * @return object
     */
    public function makeWithCollection($collection){
            
        if (is_array($collection)) {
             $collection = collect($collection);
        }

        // Filter section
        if($this->_request->exists('filter')){
            $mapped = $collection->map(function($items, $key){
                foreach($items as $item){
                    if(str_contains(Str::lower($item), Str::lower($this->_request->filter))) {
                        return $items;
                    }
                }
            });
            
            // Cleanup collection
            $mapped = $mapped->reject(function ($row, $key) {
                return is_null($row);
            });

        }else{
            $mapped = $collection;
        };

        // Sort section
        if($this->_request->has('sort')){
            $sorts = explode(',', $this->_request->sort);

            foreach ($sorts as $sort) {
                list($sortCol, $sortDir) = explode('|', $sort);

                if($sortDir == 'asc'){
                    $sorted = $mapped->sortBy($sortCol);
                }else{
                    $sorted = $mapped->sortByDesc($sortCol);
                }
            
            }
        }else{
            $sorted = $mapped;
        }

        // Clean up collection keys
        $final = collect();

        foreach($sorted as $sort){
            $final->push($sort);
        }

        // Pagination time
        $perPage = $this->_request->has('per_page') ? (int)$this->_request->per_page : null;
        $pagination = new LengthAwarePaginator(
            $final->forPage(Paginator::resolveCurrentPage(), $perPage),
            $final->count(), $perPage,
            Paginator::resolveCurrentPage(),
            ['path' => Paginator::resolveCurrentPath()]
        );


        $pagination->appends([
            'sort' => $this->_request->sort,
            'filter' => $this->_request->filter,
            'per_page' => $this->_request->per_page
        ]);


        return $pagination;

    }

    private function engine($query){
        // Sort section
        if ($this->_request->has('sort')) {
            $sorts = explode(',', $this->_request->sort);

            foreach ($sorts as $sort) {
                list($sortCol, $sortDir) = explode('|', $sort);
                if (str_contains($sortCol, '.')) {
                    $col = explode('.', $sortCol);

                    $query = $query->whereHas(str_singular($col[0]), function ($q) use ($col, $sortDir) {
                        $q->orderBy($col[1], $sortDir);
                    })->orderBy($col[1], $sortDir);

                } else {
                    $query = $query->orderBy($sortCol, $sortDir);
                }
            }
        }

        // Filter section
        if ($this->_request->exists('filter') and $this->_request->filter !== null) {

            // Get key words
            $keys = $this->getKeyWords();

            // Get models columns
            $mainCols = $this->getColumns();
            $relatedCols = $this->getColumns(true);

            $query = $query->where(function ($q) use ($mainCols, $relatedCols, $keys) {
                foreach ($keys as $key) {
                    // Iterate through main
                    foreach ($mainCols as $col) {
                        $q->orWhere($col, 'like', $key);
                    }

                    // Iterate through relationships
                    foreach ($this->_relationships as $relationship) {
                        $q->where(function ($rq) use ($relationship, $key, $relatedCols) {
                            $rq->whereHas($relationship, function ($rrq) use ($relationship, $key, $relatedCols) {
                                foreach ($relatedCols[$relationship] as $rCol) {
                                    $rrq->orWhere($rCol, 'like', $key);
                                }
                            });
                        });
                    }
                };
            });
        }

        // Terminate with pagination
        $perPage = $this->_request->has('per_page') ? (int)$this->_request->per_page : null;
        $pagination = $query->paginate($perPage);
        $pagination->appends([
            'sort' => $this->_request->sort,
            'filter' => $this->_request->filter,
            'per_page' => $this->_request->per_page
        ]);
        return $pagination;
    }

    // Get model from request
    /**
     * Undocumented function
     *
     * @return string 
     */
    private function getModel(){
        $appName = $this->getAppNamespace();

        $namespace = $appName . Str::ucfirst(str_singular($this->_request->input('model')));

        return new $namespace;

    }

    // Get keyword form request
    /**
     * Undocumented function
     *
     * @return void
     */
    private function getKeyWords(){
        // Define all seperators
        $separators = [':', '-', '_', ';'];

        // Define
        $datas = collect();

        if (str_contains($this->_request->filter, $separators)) {
            foreach ($separators as $separator) {
                // Stock seperator
                $this->_separator = $separator;

                // Extract needed datas
                $parts = explode($separator, $this->_request->filter);

                foreach ($parts as $part) {
                    if (!empty($part)) {
                        $datas->push('%'.trim($part).'%');
                    }
                }
            }

        } else {
            $words = explode(' ', $this->_request->filter);

            foreach ($words as $word) {
                $datas->push('%'.trim($word).'%');
            }

        }

        return $datas;
    }

    // Get model's columns
    /**
     * Undocumented function
     *
     * @param boolean $related
     * @return void
     */
    private function getColumns($related = false){
        if(!$related){
            return DB::getSchemaBuilder()->getColumnListing($this->_request->model);
        }else{
            $related = collect();

            foreach($this->_relationships as $relationship){
                $related->put($relationship, DB::getSchemaBuilder()->getColumnListing($this->cleanName($relationship)));
            }

            return $related;
        }

    }

    /**
     * Clean model name
     *
     * @param [type] $name
     * @return str
     */
    private function cleanName($name){
        return str_plural(str_singular($name));
    }

    /**
     * Create array of key value
     *
     * @param [type] $cols
     * @param [type] $keys
     * @return void
     */
    private function createWhereQuery($cols, $keys){
        $search = collect();

        foreach($cols as $column){
            foreach($keys as $key){

                // Create object for each
                $obj = new \stdClass();
                $obj->column = $column;
                $obj->value = $key;

                $search->push($obj);
            }
        }

        return $search;
    }

    /**
     * Undocumented function
     *
     * @param [type] $relationships
     * @param [type] $keys
     * @return void
     */
    private function createRelatedWhere($relationships, $keys){
        // Init return collection
        $related = collect();

        foreach($relationships as $key => $relationship){
            $related->put($key, $this->createWhereQuery($relationship, $keys));
        }

        return $related;
    }
}
