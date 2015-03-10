<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

// include Low Search module class
if ( ! class_exists('Low_search'))
{
	require_once(PATH_THIRD.'low_search/mod.low_search.php');
}

/**
 * Efficient multifaceted navigation using Low Search
 *
 * @package             Resistor
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2015 Hallmark Design
 * @link                http://hallmark-design.co.uk
 */

class Resistor extends Low_search {

	public $EE;
	protected $params;
	private $_channel_class;
	private $_order = 'entry_id'; // some search filters impose a fixed order on the resultset
	private static $_cache;

	protected $_filter_params = array(
		'collection',
		'channel',
		'keywords',
		'loose_ends',
		'min_score',
		'search_mode',
		'exact',
		'ends_with',
		'starts_with',
		'exact',
		'exclude',
		'tag_id',
		'tag_name',
		'websafe_separator',
		'category',
		'require_all',
		'search',
		'distance',
		'range',
		'child',
		'parent',
		'low_events'
	);

	/** 
	 * Constructor
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() 
	{
		parent::__construct();
		$this->EE = get_instance(); // required by Stash
		$this->params  =& ee()->low_search_params; // required by Low Search

		// use Stash for generating lists of related items
		if ( ! class_exists('Stash'))
		{
    		include_once PATH_THIRD . 'stash/mod.stash.php';
		}

		// model
		ee()->load->model('resistor_model');

		// channel class
		$this->_channel_class = ee()->TMPL->fetch_param('class', 'channel');
	}

	/*
    ================================================================
    Results
    ================================================================
    */

	/** 
	 * Search results
	 *
	 * @access public
	 * @return void
	 */
	public function results() 
	{	
		// search only default language relationships
		if ( isset(ee()->publisher_lib))
        {
			ee()->publisher_lib->lang_id = 1;
		}

		$params  = array(); // used for first pass of filters
		$refine  = array(); // used for second pass of filters
		$entry_ids = array(); // found entries

		// channel entries module native filters
		$stime = $etime = $channel = FALSE;

		// register params
		$cache_id = ee()->TMPL->fetch_param('id', 'default');
		$future = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('show_future_entries'));
		$dynamic = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('dynamic'));

		// when the filters listed in 'fallback_when_empty' are ALL empty, show everything
		// if you don't specify this parameter then results are only shown when one or more filters match
		$fallback_when_empty = ee()->TMPL->fetch_param('fallback_when_empty', FALSE);

		$original_params = ee()->TMPL->tagparams; // make a copy

		// --------------------------------------
		// Split parameters into two parts.
		// Params prefixed with 'refine:' will be 
		// used in the second pass of the 
		// search filters.
		// --------------------------------------
		foreach ($original_params as $key => $val)
		{
			if ( $val !== "")
			{
				if (strncmp($key, 'refine:', 7) == 0)
				{
					$refine[substr($key, 7)] = $val;
				}
				else
				{
					// account for prefixed parameters
					$key_parts = explode(':', $key);
					$search = $key_parts[0];

					// test to see if the parameter is a valid filter
					if ( in_array($search, $this->_filter_params))
					{
						$params[$key] = $val;
					}
				}
			}
		}

		// --------------------------------------
		// Narrow resulset by date range?
		// --------------------------------------

		// start on / stop before
		if (isset($original_params['start_on']) && ! empty($original_params['start_on']))
		{
			$stime = ee()->localize->string_to_timestamp($original_params['start_on']);
		}

		if (isset($original_params['stop_before']) && ! empty($original_params['stop_before']))
		{
			$etime = ee()->localize->string_to_timestamp($original_params['stop_before']);
		}
		
		// year / month / day
		if (   (isset($original_params['year'])  && ! empty($original_params['year']))
			OR (isset($original_params['month']) && ! empty($original_params['month']))
			OR (isset($original_params['day'])   && ! empty($original_params['day'])) 
		)
		{
			ee()->load->helper('date');

			$year	= ( ! isset($original_params['year']) 	OR ! is_numeric($original_params['year'])) 	? date('Y') : $original_params['year'];
			$smonth	= ( ! isset($original_params['month']) 	OR ! is_numeric($original_params['month']))	? '01' 		: $original_params['month'];
			$emonth	= ( ! isset($original_params['month']) 	OR ! is_numeric($original_params['month']))	? '12'		:  $original_params['month'];
			$day	= ( ! isset($original_params['day']) 	OR ! is_numeric($original_params['day']))	? '' 		: $original_params['day'];

			if ($day != '' AND ! is_numeric($original_params['month']))
			{
				$smonth = date('m');
				$emonth = date('m');
			}

			if (strlen($smonth) == 1)
			{
				$smonth = '0'.$smonth;
			}

			if (strlen($emonth) == 1)
			{
				$emonth = '0'.$emonth;
			}

			if ($day == '')
			{
				$sday = 1;
				$eday = days_in_month($emonth, $year);
			}
			else
			{
				$sday = $day;
				$eday = $day;
			}

			$stime = ee()->localize->string_to_timestamp($year.'-'.$smonth.'-'.$sday.' 00:00');
			$etime = ee()->localize->string_to_timestamp($year.'-'.$emonth.'-'.$eday.' 23:59');
		}

		// --------------------------------------
		// Narrow resulset by channel?
		// --------------------------------------

		// reduce results to published entries in the specified channel and date range
		if (isset($original_params['channel']) && ! empty($original_params['channel']))
		{
			$channel = ee()->resistor_model->get_channel_ids(explode('|', $original_params['channel']));	
		}

		// --------------------------------------
		// Run Low Search filters
		// --------------------------------------

		// if filter parameters are empty, we need to provide a fallback
		$do_filters = TRUE;

		if ($fallback_when_empty)
		{ 
			$do_filters = FALSE;

			// test first pass params
			$fallback_when_empty = explode('|', $fallback_when_empty);
			
			foreach ($fallback_when_empty as $field)
			{
				if ( isset($params[$field]) && ! empty($params[$field]))
				{
					$do_filters = TRUE;
					break;
				}
			}

			// test second pass if first pass params are empty
			if ( ! $do_filters && ! empty($refine))
			{
				foreach ($fallback_when_empty as $field)
				{
					if ( isset($refine[$field]) && ! empty($refine[$field]))
					{
						$do_filters = TRUE;

						// we don't need first pass, so set first pass params to refine params
						$params = $refine;
						$refine = array();

						break;
					}
				}
			}
		}

		// overwrite TMPL tag params
		ee()->TMPL->tagparams = $params;

		// generate a valid set of params for Low Search
		$this->params->set();
		$this->params->combine();
		$this->params->set_defaults();

		if ($do_filters)
		{	
			// load filter library
			ee()->load->library('Low_search_filters');

			#$cache_key = md5(json_encode($original_params));

			#if (FALSE == $entry_ids = $this->_get_cache($cache_key))
			#{
				$reset_filters = TRUE;

				// -------------------------------------
				// 'low_search_pre_search' hook.
				//  - Do something just before the search is executed
				// -------------------------------------

				if (ee()->extensions->active_hook('low_search_pre_search') === TRUE)
				{
					$params = $this->params->get();
					$params = ee()->extensions->call('low_search_pre_search', $params);

					if (ee()->extensions->end_script === TRUE) return ee()->TMPL->tagdata;

					if (isset($params['entry_id']))
					{
						if ( ! is_array($params['entry_id']))
						{
							$params['entry_id'] = explode('|', $params['entry_id']);
						}

						// set initial entry ids before filters are applied
						ee()->low_search_filters->set_entry_ids($params['entry_id']);
						$reset_filters = FALSE;
					}
				}

				// --------------------------------------
				// First pass of filters
				// --------------------------------------

				ee()->low_search_filters->filter($reset_filters);
				$entry_ids = ee()->low_search_filters->entry_ids();

				// --------------------------------------
				// Second pass of filters: refine resultset
				// --------------------------------------
				if ( is_array($entry_ids) && ! empty($entry_ids) && ! empty($refine))
				{

					ee()->TMPL->tagparams = $refine;
					$this->params->set();
					$this->params->combine();
					$this->params->set_defaults();

					// runs filters without resetting
					ee()->low_search_filters->filter(false);

					// get our filtered resultset
					$entry_ids = ee()->low_search_filters->entry_ids();
				}
	
				if (is_array($entry_ids) && ! empty($entry_ids))
				{
					// filter entry ids by channel, start time and/or end time
					if ($channel OR $stime OR $etime) 
					{
						$entry_ids = ee()->resistor_model->get_entries($entry_ids, $channel, $future, $stime, $etime);
					}

					// set search results order
					if ( isset($original_params['orderby']) && ! empty($original_params['orderby']) )
					{
						$this->_order = 'entry_id';
					}
					elseif (ee()->low_search_filters->fixed_order())
					{
						$this->_order = 'fixed_order';
					}
					else
					{
						$this->_order = 'entry_id';
					}

					// cache
					#if (is_array($entry_ids) && ! empty($entry_ids))
					#{
					#	$this->_set_cache($cache_key, $entry_ids);
					#}
				}
			#}
		}
		else
		{
			// No filtering

			// fallback to dynamic entries tag?
			if ( $dynamic)
			{
				ee()->TMPL->tagparams = $original_params;
				return $this->entries();
			}
			else
			{
				// fallback, get all entries in a given channel and/or date range
				if (isset($original_params['channel']))
				{
					if ($channel OR $stime OR $etime)
					{
						$entry_ids = ee()->resistor_model->get_entries(array(), $channel, $future, $stime, $etime);
					}	
				}
			}
		}

		// save to static cache for use by this and later-parsed tags
		$cache_id = ee()->TMPL->fetch_param('id', 'default');
		self::$_cache[$cache_id] = $entry_ids;

		// --------------------------------------
		// Render the entries
		// --------------------------------------

		// remove any filter parameters
		$safe_params = array();

		foreach($original_params as $key => $value)
		{
			// account for prefixed parameters
			$key_parts = explode(':' ,$key);
			$search = $key_parts[0];

			if ( ! in_array($search, $this->_filter_params))
			{
				$safe_params[$key] = $value;
			}
		}

		// set channel
		if (isset($original_params['channel']) && ! empty($original_params['channel']))
		{
			$safe_params['channel'] = $original_params['channel'];
		}

		ee()->TMPL->tagparams = $safe_params;

		// make sure search_fields array is empty
		ee()->TMPL->search_fields = array();

		// empty array -> No results
		if (empty($entry_ids))
		{
			$this->_log('Filters found no matches, returning no results');
		}
		// we have results! But which param should we populate?
		else
		{
			// set the entry_id/fixed_order param
			ee()->TMPL->tagparams[$this->_order] = low_implode_param($entry_ids);

			// -------------------------------------
			// 'low_search_post_search' hook.
			//  - Do something just after the search is executed
			// -------------------------------------
			if (ee()->extensions->active_hook('low_search_post_search') === TRUE)
			{
				ee()->TMPL->tagparams = ee()->extensions->call('low_search_post_search', ee()->TMPL->tagparams);
				if (ee()->extensions->end_script === TRUE) return ee()->TMPL->tagdata;
			}

			// let's render those mothers
			return $this->_channel_entries($this->_channel_class);
		}	
	}


	/** 
	 * Entries
	 *
	 * @access public
	 * @return void
	 */
	public function entries() 
	{
		return $this->_channel_entries($this->_channel_class);
	}

	/*
    ================================================================
    Related
    ================================================================
    */

	/** 
	 * Related children
	 *
	 * @access public
	 * @return string
	 */
	public function children() 
	{
		// set parameters
		$id = ee()->TMPL->fetch_param('id', 'default');
		$channel_id = ee()->TMPL->fetch_param('channel_id', FALSE);
		$parent_channel_id = ee()->TMPL->fetch_param('parent_channel_id', 0);
		$parent_field_id = ee()->TMPL->fetch_param('parent_field_id', FALSE);
		$taxonomy_tree_id = ee()->TMPL->fetch_param('taxonomy_tree_id', FALSE);
		$taxonomy_entry_id = ee()->TMPL->fetch_param('taxonomy_entry_id', FALSE);
		$taxonomy_depth = ee()->TMPL->fetch_param('taxonomy_depth', 1);
		$show_all = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('show_all'));
		$list_key = ee()->TMPL->fetch_param('name', FALSE);

		if ($channel_id && $parent_field_id)
		{	
			if (FALSE == $list_key)
			{
				$list_key = $id . ':' . $channel_id . '_' . $parent_channel_id . '_' . $parent_field_id .'_children';
			}

			// show relationships to a given set of entries, or all relationships for the specified field & channel?
			if (FALSE === $show_all && isset(self::$_cache[$id])) 
			{
				$entry_ids = self::$_cache[$id];

			}
			else
			{
				$entry_ids = array();
			}

			if ($related = ee()->resistor_model->get_children(
				$channel_id,
				$parent_channel_id, 
				$parent_field_id, 
				$entry_ids,
				$taxonomy_tree_id,
				$taxonomy_entry_id
			))
			{
				// narrow to a specific branch in a Taxonomy tree?
                if ($taxonomy_tree_id)
                {
                    // get all entries in the specified branch
                    if ($tax_children = ee()->resistor_model->get_taxonomy_children($taxonomy_tree_id, $taxonomy_entry_id, $taxonomy_depth))
                    {
                    	// compare the two resultsets and remove entries not in the Taxonomy branch
                    	$filtered_results = array();
                    	foreach ($related as $row)
                    	{
                    		if (in_array($row['entry_id'], $tax_children))
                    		{
                    			$filtered_results[] = $row;
                    		}
                    	}
                    	$related = $filtered_results;
                    }
                }

				// format as a serialised stash list and set as a variable
				$list = Stash::flatten_list($related);
				Stash::set($list_key, $list);

				// output as a stash list
				ee()->TMPL->tagparams['name'] = $list_key;
				return Stash::get_list(ee()->TMPL->tagparams, ee()->TMPL->tagdata);
			}
		}
	}

	/** 
	 * Related categories
	 *
	 * @access public
	 * @return string
	 */
	public function categories() 
	{
		// set parameters
		$id = ee()->TMPL->fetch_param('id', 'default');
		$group_id = ee()->TMPL->fetch_param('group_id', FALSE);

		$list = array();

		if ($group_id && isset(self::$_cache[$id]))
		{
			$list_key = $id . ':' . $group_id . '_categories';
			$group_id = explode('|', $group_id);

			if ($related = ee()->resistor_model->get_categories(
				$group_id, 
				self::$_cache[$id]
			))
			{
				// format as a serialised stash list and set as a variable
				$list = Stash::flatten_list($related);
				Stash::set($list_key, $list);
			}

			// output as a stash list
			ee()->TMPL->tagparams['name'] = $list_key;
			return Stash::get_list(ee()->TMPL->tagparams, ee()->TMPL->tagdata);
		}
	}

	/** 
	 * Selected categories
	 *
	 * @access public
	 * @return string
	 */
	public function selected_categories() 
	{
		if ($cat_ids = ee()->TMPL->fetch_param('group_id', FALSE))
		{
			$delimiter =  ee()->TMPL->fetch_param('delimiter', '|');
			$cat_ids = explode($delimiter, $cat_ids);

			$related = ee()->resistor_model->get_categories_by_id($cat_ids);
		}
	}

	/** 
	 * Related collections
	 *
	 * @access public
	 * @return string
	 */
	public function collections() 
	{
		// set parameters
		$id = ee()->TMPL->fetch_param('id', 'default');
		$list = array();

		if (isset(self::$_cache[$id]))
		{
			$list_key = $id . ':' . '_collections';

			if ($related = ee()->resistor_model->get_collections(
				self::$_cache[$id]
			))
			{
				// format as a serialised stash list and set as a variable
				$list = Stash::flatten_list($related);
				Stash::set($list_key, $list);
			}

			// output as a stash list
			ee()->TMPL->tagparams['name'] = $list_key;
			return Stash::get_list(ee()->TMPL->tagparams, ee()->TMPL->tagdata);
		}
	}

	/** 
	 * Related channels
	 *
	 * @access public
	 * @return string
	 */
	public function channels() 
	{	
		// set parameters
		$id = ee()->TMPL->fetch_param('id', 'default');
		$list = array();

		if (isset(self::$_cache[$id]))
		{
			$list_key = $id . ':' . '_channels';

			if ($related = ee()->resistor_model->get_channels(
				self::$_cache[$id]
			))
			{
				// format as a serialised stash list and set as a variable
				$list = Stash::flatten_list($related);
				Stash::set($list_key, $list);
			}

			// output as a stash list
			ee()->TMPL->tagparams['name'] = $list_key;
			return Stash::get_list(ee()->TMPL->tagparams, ee()->TMPL->tagdata);
		}
	}


	/** 
	 * Related tags
	 *
	 * @access public
	 * @return string
	 */
	public function tags() 
	{
		// set parameters
		$id = ee()->TMPL->fetch_param('id', 'default');
		$limit = (int) ee()->TMPL->fetch_param('limit', '20');

		$list = array();

		if (isset(self::$_cache[$id]))
		{
			$list_key = $id . ':' . '_tags';

			if ($related = ee()->resistor_model->get_tags(
				self::$_cache[$id],
				$limit
			))
			{
				// format as a serialised stash list and set as a variable
				$list = Stash::flatten_list($related);
				Stash::set($list_key, $list);
			}

			// output as a stash list
			ee()->TMPL->tagparams['name'] = $list_key;
			return Stash::get_list(ee()->TMPL->tagparams, ee()->TMPL->tagdata);
		}
	}

	/** 
	 * Related years
	 *
	 * @access public
	 * @return string
	 */
	public function years() 
	{
		// set parameters
		$id = ee()->TMPL->fetch_param('id', 'default');
		$list = array();

		if (isset(self::$_cache[$id]))
		{
			$list_key = $id . ':' . '_years';

			if ($related = ee()->resistor_model->get_years(
				self::$_cache[$id]
			))
			{
				// format as a serialised stash list and set as a variable
				$list = Stash::flatten_list($related);
				Stash::set($list_key, $list);
			}

			// output as a stash list
			ee()->TMPL->tagparams['name'] = $list_key;
			return Stash::get_list(ee()->TMPL->tagparams, ee()->TMPL->tagdata);
		}
	}

	/** 
	 * Related archive
	 *
	 * @access public
	 * @return string
	 */
	public function archive() 
	{
		// set parameters
		$id = ee()->TMPL->fetch_param('id', 'default');
		$list = array();

		if (isset(self::$_cache[$id]))
		{
			$list_key = $id . ':' . '_archive';

			if ($related = ee()->resistor_model->get_archive(
				self::$_cache[$id]
			))
			{
				// format as a serialised stash list and set as a variable
				$list = Stash::flatten_list($related);
				Stash::set($list_key, $list);
			}

			// output as a stash list
			ee()->TMPL->tagparams['name'] = $list_key;
			return Stash::get_list(ee()->TMPL->tagparams, ee()->TMPL->tagdata);
		}
	}

	// --------------------------------------------------------------------

	/**
	 * Loads the Channel module and runs its entries() method
	 *
	 * @access  private
	 * @param 	$class The channel entries module class to use
	 * @return  void
	 */
	private function _channel_entries($class)
	{
		// --------------------------------------
		// Make sure the following params are set
		// --------------------------------------

		$set_params = array(
			'dynamic'  => 'no',
			'paginate' => 'bottom'
		);

		foreach ($set_params AS $key => $val)
		{
			if ( ! ee()->TMPL->fetch_param($key))
			{
				ee()->TMPL->tagparams[$key] = $val;
			}
		}

		// --------------------------------------
		// Get channel module?
		// --------------------------------------

		if ($class == 'minimal')
		{
			// minimal channel entries rendering
			return $this->minimal_entries();
		}
		else
		{	
			$this->_log('Calling the ' . $class . ' module');

			if ($class=="channel")
			{
				// render with the native channel entries module
				if ( ! class_exists('channel'))
				{
					require_once PATH_MOD.'channel/mod.channel'.EXT;
				}
			}
			else
			{
				// render with a third party channel entries subclass
				if ( ! class_exists($class))
				{
					require_once PATH_THIRD . $class . '/mod.' . $class . EXT;
				}
			}

			// Create new Channel instance
			$channel = new $class;

			// Let the Channel module do all the heavy lifting
			return $channel->entries();
		}
	}

	/** 
	 * Minimal entries
	 * 
	 * Hydrates an array of entries
	 *
	 * @access public
	 * @param string $key
	 * @return array
	 */
	public function minimal_entries()
	{
		$list_html      = '';
        $list_markers   = array(); 

		// --------------------------------------
		// Register parameters
		// --------------------------------------

		// sort
		$sort = ee()->TMPL->fetch_param('sort', 'desc');

		// orderby
		$order_by = ee()->TMPL->fetch_param('orderby', 'entry_date');

		// prefix: optional namespace for common vars like {count}
		$prefix = ee()->TMPL->fetch_param('prefix', NULL);         

		// limit
		$limit = ee()->TMPL->fetch_param('limit', 100);

		// offset
		$offset = ee()->TMPL->fetch_param('offset', 0);

		// pagination
		$paginate = ee()->TMPL->fetch_param('paginate', FALSE);
        $paginate_param = ee()->TMPL->fetch_param('paginate_param', NULL); // if using query string style pagination

		// entry ids
		if ($entry_id = ee()->TMPL->fetch_param('entry_id', FALSE))
		{
			$entry_id = preg_split('/\|/', $entry_id, -1, PREG_SPLIT_NO_EMPTY);
		}

		// fixed entry id ordering
		if ($fixed_order = ee()->TMPL->fetch_param('fixed_order', FALSE))
		{
			// convert to an array	
			$fixed_order = preg_split('/\|/', $fixed_order, -1, PREG_SPLIT_NO_EMPTY);

			// MySQL will not order the entries correctly unless the results are constrained to matching rows only
			$entry_id = $fixed_order;

			// flip sort?
			if ($sort == 'desc')
			{
				$fixed_order = array_reverse($fixed_order);
			}
		}

		// bail out if we don't have valid entry_id / fixed order params
		// or no results
		if (FALSE === $entry_id)
		{
			// check for prefixed no_results block
	        if ( ! is_null($prefix))
	        {
	            $this->_prep_no_results($prefix);
	        }

			return $this->_no_results();
		}

        // get the current absolute count of *open* non-expired entries
        // important because our array of entry_ids may have been cached previously
		$absolute_results = ee()->resistor_model->count_entries($entry_id, FALSE);

		// bail out if no actual entries are found
		if ( ! $absolute_results) 
		{
			return;
		}

        // --------------------------------------
		// Pagination
		// --------------------------------------

        if ($paginate)
        {   
            // remove prefix if used in the paginate tag pair
            if ( ! is_null($prefix))
            {
                if (preg_match("/(".LD.$prefix.":paginate".RD.".+?".LD.'\/'.$prefix.":paginate".RD.")/s", ee()->TMPL->tagdata, $paginate_match))
                {
                    $paginate_template = str_replace($prefix.':','', $paginate_match[1]);
                    ee()->TMPL->tagdata = str_replace($paginate_match[1], $paginate_template, ee()->TMPL->tagdata);
                }
            }
                    
            // pagination template
            ee()->load->library('pagination');
            
            // are we passing the offset in the query string?
            if ( ! is_null($paginate_param))
            {
                // prep the base pagination object
                ee()->pagination->query_string_segment = $paginate_param;
                ee()->pagination->page_query_string = TRUE;
            }
            
            // create a pagination object instance
            if (version_compare(APP_VER, '2.8', '>=')) 
            { 
                $this->pagination = ee()->pagination->create();
            } 
            else
            {
                $this->pagination = new Pagination_object(__CLASS__);
            }

            // pass the offset to the pagination object
            if ( ! is_null($paginate_param))
            {
                // we only want the offset integer, ignore the 'P' prefix inserted by EE_Pagination
                $this->pagination->offset = filter_var(ee()->input->get($paginate_param, TRUE), FILTER_SANITIZE_NUMBER_INT);
                
                if ( ! is_null(ee()->TMPL->fetch_param('paginate_base', NULL)))
                {
                    // make sure paginate_base ends with a '?', if specified
                    $base=ee()->TMPL->tagparams['paginate_base'];
                    ee()->TMPL->tagparams['paginate_base'] = $base.((!strpos($base, '?'))? '?': '');
                }
            }
            else
            {
                $this->pagination->offset = 0;
            }

            // determine pagination limit & total rows
            $page_limit = $limit ? $limit : 100; // same default limit as channel entries module
            $page_total_rows = $absolute_results - $offset;
            
            if (version_compare(APP_VER, '2.8', '>=')) 
            { 
                 // find and remove the pagination template from tagdata wrapped by get_list
                ee()->TMPL->tagdata = $this->pagination->prepare(ee()->TMPL->tagdata);

                // build
                $this->pagination->build($page_total_rows, $page_limit);
            }
            else
            {
                $this->pagination->per_page = $page_limit;
                $this->pagination->total_rows = $page_total_rows;
                $this->pagination->get_template();
                $this->pagination->build();
            }
            
            // update offset
            $offset = $offset + $this->pagination->offset;
        }

        // --------------------------------------
		// Hydrate entries and apply sort/order
		// --------------------------------------
		$list = ee()->resistor_model->hydrate_entries(
			$entry_id, 
			$order_by, 
			$sort, 
			$limit, 
			$offset, 
			$fixed_order, 
			FALSE
		);

        // --------------------------------------
		// List markers
		// --------------------------------------

        // {absolute_results} - record the total number of list rows
        $list_markers['absolute_results'] = $absolute_results;

        if ( ! is_null($prefix))
        {
            // {prefix:absolute_results}
            $list_markers[$prefix.':absolute_results'] = $list_markers['absolute_results'];
            
            // {prefix:total_results}
            $list_markers[$prefix.':total_results'] = count($list);

            // {prefix:count}
            $i=0;
            foreach($list as $key => &$v)
            {
                $i++;
                $v[$prefix.':count'] = $i;
            }
            
            // {prefix:switch = ""}
            if (strpos(ee()->TMPL->tagdata, LD.$prefix.':switch') !== FALSE)
            {
                ee()->TMPL->tagdata = str_replace(LD.$prefix.':switch', LD.'switch', ee()->TMPL->tagdata);
            }   
        } 

        // --------------------------------------
		// Render template
		// --------------------------------------  

        // disable backspace param to stop parse_variables() doing it automatically
        // because it can potentially break unparsed conditionals / tags etc in the list
        $backspace = ee()->TMPL->fetch_param('backspace', FALSE);
        ee()->TMPL->tagparams['backspace'] = FALSE;

        $list_html = ee()->TMPL->parse_variables(ee()->TMPL->tagdata, $list);
    
        // restore original backspace parameter
        ee()->TMPL->tagparams['backspace'] = $backspace;
    
        // parse other markers
        $list_html = ee()->TMPL->parse_variables_row($list_html, $list_markers);
        
        // render pagination
        if ($paginate)
        {
            $list_html = $this->pagination->render($list_html);
        }

        return $list_html;
	}
	
	// ---------------------------------------------------------
    
    /**
     * prep a prefixed no_results block in current template tagdata
     * 
     * @access public
     * @param string $prefix
     * @return String   
     */ 
    function _prep_no_results($prefix)
    {
        if (strpos(ee()->TMPL->tagdata, 'if '.$prefix.':no_results') !== FALSE 
                && preg_match("/".LD."if ".$prefix.":no_results".RD."(.*?)".LD.'\/'."if".RD."/s", ee()->TMPL->tagdata, $match)) 
        {
            if (stristr($match[1], LD.'if'))
            {
                $match[0] = ee()->functions->full_tag($match[0], $block, LD.'if', LD.'\/'."if".RD);
            }
        
            $no_results = substr($match[0], strlen(LD."if ".$prefix.":no_results".RD), -strlen(LD.'/'."if".RD));
            $no_results_block = $match[0];
            
            // remove {if prefix:no_results}..{/if} block from template
            ee()->TMPL->tagdata = str_replace($no_results_block, '', ee()->TMPL->tagdata);
            
            // set no_result variable in Template class
            ee()->TMPL->no_results = $no_results;
        }
    }

 	// ---------------------------------------------------------
    
    /**
     * parse and return no_results content
     * 
     * @access public
     * @param string $prefix
     * @return String   
     */ 
    function _no_results()
    {
        if ( ! empty(ee()->TMPL->no_results))
        {
            // parse the no_results block if it's got content
            ee()->TMPL->no_results = $this->_parse_output(ee()->TMPL->no_results);
        }
        return ee()->TMPL->no_results();
    }

	/** 
	 * Search results
	 *
	 * @access private
	 * @param string $key
	 * @return array
	 */
	private function _get_cache($key)
	{
		$params = array(
		    'name'  	=> $key,
		    'scope' 	=> 'site',
		    'bundle'	=> 'search'
		);
		$result = Stash::get($params);

		if ($result == "")
		{
			return FALSE;
		} 
		else
		{
		 	$result = explode('|', $result);

		 	// update the order of the search results
		 	$this->_order = array_pop($result);

		 	return $result;
		}
	}

	/** 
	 * Search results
	 *
	 * @access private
	 * @param string $key
	 * @param array $value
	 * @return void
	 */
	private function _set_cache($key, $value)
	{
		$params = array(
		    'name'  	=> $key,
		    'scope' 	=> 'site',
		    'bundle'	=> 'search',
		    'save'		=> 'yes',
		    'replace'	=> 'yes',
		    'refresh' 	=> 360
		);

		// save the order of the filtered search results in the cache
		$value = implode('|', $value) . '|' . $this->_order;

		Stash::set($params, $value);
	}

	/**
	 * Log message
	 *
	 * @access     private
	 * @param      string
	 * @return     void
	 */
	private function _log($msg)
	{
		ee()->TMPL->log_item("Resistor: {$msg}");
	}

}

/* End of file mod.resistor.php */
/* Location: ./system/expressionengine/third_party/resistor/mod.resistor.php */	