<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Efficient multifaceted navigation using Low Search
 *
 * @package             Resistor
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2015 Hallmark Design
 * @link                http://hallmark-design.co.uk
 */

class Resistor_model extends CI_Model {

    private static $_cache;

    function __construct()
    {
        parent::__construct();

        // setup static cache
        self::$_cache = array(
            'children'      => array(),
            'categories'    => array(),
            'fields'        => array()
        );
    }

    /**
     * Get unique years and counts for a given set of entries
     *
     * @param   array $entry_ids
     * @access  public
     * @return  array
     */
    public function get_years($entry_ids)
    {
        if (empty($entry_ids)) return FALSE;

        $entry_ids = implode(",", array_map( array($this->db, 'escape'), $entry_ids));

        // in cache?
        $cache_key = md5($entry_ids);

        if ( ! isset(self::$_cache['years'][$cache_key]))
        {   
            $now = ee()->localize->now;

            $sql = "SELECT year, COUNT(entry_id) as cnt 
                    FROM exp_channel_titles 
                    WHERE site_id = 1
                    AND status = 'open'
                    AND entry_date < {$this->db->escape($now)} 
                    AND (expiration_date = 0 OR expiration_date > {$now})
                    AND entry_id IN ({$entry_ids})  
                    GROUP BY year
                    ORDER BY year DESC";

            $query = $this->db->query($sql);
            
            if ($query->num_rows() > 0)
            {
                self::$_cache['years'][$cache_key] = $query->result_array();
            }
            else
            {
                self::$_cache['years'][$cache_key] = FALSE;
            } 
        }
        
        return self::$_cache['years'][$cache_key];
    }

    /**
     * Get unique year-months and counts for a given set of entries
     *
     * @param   array $entry_ids
     * @access  public
     * @return  array
     */
    public function get_archive($entry_ids)
    {
        if (empty($entry_ids)) return FALSE;

        $entry_ids = implode(",", array_map( array($this->db, 'escape'), $entry_ids));

        // in cache?
        $cache_key = md5($entry_ids);

        if ( ! isset(self::$_cache['archive'][$cache_key]))
        {   
            $now = ee()->localize->now;

            $sql = "SELECT year, LPAD(month, 2,'0') as xmonth, month, COUNT(entry_id) as cnt 
                    FROM exp_channel_titles 
                    WHERE site_id = 1
                    AND status = 'open'
                    AND entry_date < {$this->db->escape($now)} 
                    AND (expiration_date = 0 OR expiration_date > {$now})
                    AND entry_id IN ({$entry_ids})  
                    GROUP BY year, xmonth
                    ORDER BY year DESC, xmonth DESC";

            $query = $this->db->query($sql);
            
            if ($query->num_rows() > 0)
            {
                self::$_cache['archive'][$cache_key] = $query->result_array();
            }
            else
            {
                self::$_cache['archive'][$cache_key] = FALSE;
            } 
        }

        return self::$_cache['archive'][$cache_key];
    }

    /**
     * Get unique related children and counts for a given set of entries
     *
     * @param   integer $channel_id
     * @param   integer $parent_field_id
     * @param   array $entry_ids
     * @access  public
     * @return  array
     */
    public function get_children($channel_id, $parent_channel_id = 0, $parent_field_id, $entry_ids=array())
    {
        $entry_ids = implode(",", array_map( array($this->db, 'escape'), $entry_ids));

        // in cache?
        $cache_key = md5($channel_id . ',' . $parent_field_id . ',' . $entry_ids);

        if ( ! isset(self::$_cache['children'][$cache_key]))
        {
            $now = ee()->localize->now;

            $sql = "SELECT child.entry_id, child.title, child.url_title, count(rel.child_entry_id) as cnt
                    FROM exp_playa_relationships rel 
                    INNER JOIN exp_channel_titles AS child 
                        ON child.entry_id = rel.child_entry_id ";

                    if ( empty($entry_ids))
                    {
                        $sql .= "
                        LEFT JOIN exp_channel_titles AS parent 
                        ON parent.entry_id = rel.parent_entry_id 
                        ";
                    }

                    $sql .= " 
                        WHERE rel.parent_is_draft = 0 
                            AND rel.parent_field_id = {$this->db->escape($parent_field_id)} 
                        ";

                    if ( ! empty($entry_ids))
                    {
                        $sql .=" 
                            AND rel.parent_entry_id IN ({$entry_ids}) 
                        ";
                    }
                    else
                    {
                        $sql .="
                            AND parent.entry_date < {$this->db->escape($now)}  
                            AND (parent.expiration_date = 0 OR parent.expiration_date > {$this->db->escape($now)} )
                            AND parent.status = 'open' 
                        ";

                        if ($parent_channel_id >0)
                        {
                            $sql .="AND parent.channel_id = {$this->db->escape($parent_channel_id)} ";
                        }
                    }

                    $sql .=" 
                        AND child.entry_date < {$this->db->escape($now)} 
                        AND (child.expiration_date = 0 OR child.expiration_date > {$this->db->escape($now)})
                        AND child.channel_id = {$this->db->escape($channel_id)} 
                        AND child.status = 'open'
                    ";                        
                    
            if (isset(ee()->publisher_lib))
            {
                // Publisher support
                $sql .= "AND publisher_lang_id = 1 AND publisher_status = 'open' ";
            } 

            $sql .= "GROUP BY child.entry_id 
                     ORDER BY child.title asc";

            $query = $this->db->query($sql);
            
            if ($query->num_rows() > 0)
            {
                self::$_cache['children'][$cache_key] = $query->result_array();
            }
            else
            {
                self::$_cache['children'][$cache_key] = FALSE;
            } 
        }

        return self::$_cache['children'][$cache_key];
    }

    /**
     * Get unique related categories and counts for a given set of entries
     *
     * @param   integer/array $group_id
     * @param   array $entry_ids
     * @access  public
     * @return  array
     */
    public function get_categories($group_id, $entry_ids)
    {
        if (empty($entry_ids)) return FALSE;
        
        $entry_ids = implode(",", array_map( array($this->db, 'escape'), $entry_ids));

        if ( ! is_array($group_id))
        {
            $group_id = array($group_id);
        }
        $group_id = implode(",", array_map( array($this->db, 'escape'), $group_id));

        // in cache?
        $cache_key = md5($group_id . ',' . $entry_ids);

        if ( ! isset(self::$_cache['categories'][$cache_key]))
        {
            $sql = "SELECT c.cat_id, c.cat_name, c.cat_url_title, COUNT(cp.entry_id) as cnt 
                    FROM exp_categories c 
                    RIGHT JOIN exp_category_posts cp 
                        ON c.cat_id=cp.cat_id 
                        AND cp.entry_id IN ({$entry_ids}) 
                    WHERE c.site_id IN (1) 
                        AND c.group_id IN ({$group_id}) 
                    GROUP BY c.cat_id 
                    ORDER BY c.cat_name";

            $query = $this->db->query($sql);
            
            if ($query->num_rows() > 0)
            {
                self::$_cache['categories'][$cache_key] = $query->result_array();
            }
            else
            {
                self::$_cache['categories'][$cache_key] = FALSE;
            }
        } 

        return self::$_cache['categories'][$cache_key];      
    }

    /**
     * Get unique related channels and counts for a given set of entries
     *
     * @param   array $entry_ids
     * @access  public
     * @return  array
     */
    public function get_channels($entry_ids)
    {
        if (empty($entry_ids)) return FALSE;
        
        $entry_ids = implode(",", array_map( array($this->db, 'escape'), $entry_ids));

        $now = ee()->localize->now;

        // in cache?
        $cache_key = md5($entry_ids);

        if ( ! isset(self::$_cache['channels'][$cache_key]))
        {
            $sql = "SELECT c.channel_id, c.channel_name, c.channel_title, COUNT(t.entry_id) as cnt 
                    FROM exp_channel_titles t   
                    LEFT JOIN exp_channels c
                        ON t.channel_id = c.channel_id
                    WHERE c.site_id IN (1) 
                    AND t.status = 'open'
                    #AND t.entry_date < {$this->db->escape($now)} 
                    AND (t.expiration_date = 0 OR t.expiration_date > {$now})
                    AND t.entry_id IN ({$entry_ids})  
                    GROUP BY c.channel_id 
                    ORDER BY c.channel_title ASC";

            $query = $this->db->query($sql);
            
            if ($query->num_rows() > 0)
            {
                self::$_cache['channels'][$cache_key] = $query->result_array();
            }
            else
            {
                self::$_cache['channels'][$cache_key] = FALSE;
            }
        } 

        return self::$_cache['channels'][$cache_key];      
    }

    /**
     * Get unique related collections and counts for a given set of entries
     *
     * @param   array $entry_ids
     * @access  public
     * @return  array
     */
    public function get_collections($entry_ids)
    {
        if (empty($entry_ids)) return FALSE;
        
        $entry_ids = implode(",", array_map( array($this->db, 'escape'), $entry_ids));

        $now = ee()->localize->now;

        // in cache?
        $cache_key = md5($entry_ids);

        if ( ! isset(self::$_cache['collections'][$cache_key]))
        {
            $sql = "SELECT c.collection_id, c.collection_name, c.collection_label, COUNT(i.entry_id) as cnt 
                    FROM exp_low_search_collections c 
                    RIGHT JOIN exp_low_search_indexes i 
                        ON c.collection_id=i.collection_id 
                        AND i.entry_id IN ({$entry_ids})    
                    LEFT JOIN exp_channel_titles t
                        ON i.entry_id = t.entry_id
                    WHERE c.site_id IN (1) ";

            // Publisher support
            if ( isset(ee()->publisher_lib))
            {
                $sql .="AND i.publisher_lang_id = 1 AND i.publisher_status = 'open' ";
            }

            $sql .="AND t.status = 'open'
                    #AND t.entry_date < {$this->db->escape($now)} 
                    AND (t.expiration_date = 0 OR t.expiration_date > {$now})
                    GROUP BY c.collection_id 
                    ORDER BY c.collection_label ASC";

            $query = $this->db->query($sql);
            
            if ($query->num_rows() > 0)
            {
                self::$_cache['collections'][$cache_key] = $query->result_array();
            }
            else
            {
                self::$_cache['collections'][$cache_key] = FALSE;
            }
        } 

        return self::$_cache['collections'][$cache_key];      
    }

    /**
     * Get unique related Solpace tags and counts for a given set of entries
     *
     * @param   array $entry_ids
     * @param   integer $limit
     * @param   string $websafe_separator
     * @access  public
     * @return  array
     */
    public function get_tags($entry_ids, $limit, $websafe_separator="+")
    {
        if (empty($entry_ids)) return FALSE;
        
        $entry_ids = implode(",", array_map( array($this->db, 'escape'), $entry_ids));

        $now = ee()->localize->now;

        // in cache?
        $cache_key = md5($entry_ids);

        if ( ! isset(self::$_cache['categories'][$cache_key]))
        {
            $sql = "SELECT tags.tag_id, tags.tag_name, COUNT(entries.entry_id) as cnt 
                    FROM exp_tag_tags AS tags 
                    LEFT JOIN exp_tag_bad_tags AS bad
                        ON bad.tag_name = tags.tag_name
                    RIGHT JOIN `exp_tag_entries` AS entries 
                        ON tags.tag_id = entries.tag_id 
                    RIGHT JOIN exp_channel_titles t
                        ON t.entry_id = entries.entry_id 
                        AND t.status = 'open'
                        #AND t.entry_date < {$this->db->escape($now)} 
                        AND (t.expiration_date = 0 OR t.expiration_date > {$now}) 
                    WHERE tags.site_id IN (1) 
                    AND bad.tag_name IS NULL
                    AND entries.entry_id IN ({$entry_ids}) 
                    GROUP BY tags.tag_id 
                    ORDER BY cnt DESC
                    LIMIT {$this->db->escape($limit)}";

            $query = $this->db->query($sql);
            
            if ($query->num_rows() > 0)
            {
                // add websafe tag
                $result = $query->result_array();

                foreach($result as &$row)
                {
                    $row['websafe_tag'] = str_replace(" ", $websafe_separator, $row['tag_name']);
                }
                unset($row);

                self::$_cache['categories'][$cache_key] = $result;
            }
            else
            {
                self::$_cache['categories'][$cache_key] = FALSE;
            }
        } 

        return self::$_cache['categories'][$cache_key];      
    }


    /**
     * Get the immediate subordinates of a given entry in a Taxonomy tree
     *
     * @param   integer $entry_id
     * @param   array $tree_id
     * @access  public
     * @return  array Array of entry_ids
     */
    public function get_taxonomy_children($tree_id, $entry_id=FALSE, $depth=1)
    {
        $tree_id  = intval($tree_id);
        $depth  = intval($depth);

        $where_sql = '';
        if ($entry_id)
        {
            $entry_id  = intval($entry_id);
            $where_sql = "node.entry_id = {$this->db->escape($entry_id)}";
            $cache_key = (string) $entry_id . '|' . $tree_id . '|' . $depth;
        }
        else
        {
            $where_sql = "node.node_id = 1"; // default to top level of tree
            $cache_key = 'node_1|' . $tree_id . '|' . $depth;
        }

        // in cache?
        if ( ! isset(self::$_cache['taxonomy'][$cache_key]))
        {
            $sql = "SELECT node.node_id, node.entry_id, (COUNT(parent.node_id) - (sub_tree.depth + 1)) AS depth
                    FROM exp_taxonomy_tree_{$tree_id} AS node,
                         exp_taxonomy_tree_{$tree_id} AS parent,
                         exp_taxonomy_tree_{$tree_id} AS sub_parent,
                         (
                            SELECT node.node_id, (COUNT(parent.node_id) - 1) AS depth
                            FROM exp_taxonomy_tree_{$tree_id} AS node,
                            exp_taxonomy_tree_{$tree_id} AS parent
                            WHERE node.lft BETWEEN parent.lft AND parent.rgt
                            AND {$where_sql}
                            GROUP BY node.node_id
                            ORDER BY node.lft
                         ) AS sub_tree
                    WHERE node.lft BETWEEN parent.lft AND parent.rgt
                        AND node.lft BETWEEN sub_parent.lft AND sub_parent.rgt
                        AND sub_parent.node_id = sub_tree.node_id
                    GROUP BY node.node_id
                    HAVING depth = {$depth}
                    ORDER BY node.lft;";

            $query = $this->db->query($sql);

            if ($query->num_rows() > 0)
            {
                $entry_ids = array();

                foreach($query->result_array() as $row)
                {
                    $entry_ids[] = $row['entry_id'];
                }
                self::$_cache['taxonomy'][$cache_key] = $entry_ids;
            }
            else
            {
                self::$_cache['taxonomy'][$cache_key] = FALSE;
            }

        }

        return self::$_cache['taxonomy'][$cache_key];
    }

    /**
     * Get published entries in a given channel
     *
     * @param   array $entry_ids
     * @param   integer/array $channel_id
     * @param   bool $future
     * @param   integer $stime entry start time
     * @param   integer $etime entry end time
     * @access  public
     * @return  array/bool
     */
    public function get_entries($entry_ids = array(), $channel = FALSE, $future = FALSE, $stime = FALSE, $etime = FALSE)
    {
        $now = ee()->localize->now;

        ee()->db->select('entry_id')
                ->where('status', 'open')
                ->where("(expiration_date = 0 OR expiration_date > {$now})")
                ->order_by('title', 'asc');

        // channel
        if ($channel)
        {
            if (is_array($channel))
            {
                ee()->db->where_in('channel_id', $channel);
            }
            else
            {
                ee()->db->where('channel_id', $channel);
            }
        }

        // future entries
        if ($future)
        {
            #ee()->db->where('entry_date >', $now);
        }
        else
        {
            ee()->db->where('entry_date <', $now);
        } 

        // start and end date
        if ($stime)
        {
            ee()->db->where('entry_date >=', $stime);
        }

        if ($etime)
        {
            ee()->db->where('entry_date <=', $etime);
        }

        // specified entries
        if ( ! empty($entry_ids))
        {
            ee()->db->where_in('entry_id', $entry_ids);
        }
       
        $query = ee()->db->get('channel_titles');

        if ($query->num_rows() > 0)
        {
            $entry_ids = array();
            foreach ($query->result_array() as $row)
            {
                $entry_ids[] = $row['entry_id'];
            }
            return $entry_ids;
        }
        else
        {
            return FALSE;
        }
    }

    /**
     * Get channel ids from an array of names
     *
     * @param   array $channel_id
     * @access  public
     * @return  array/bool
     */
    public function get_channel_ids($channels)
    {
         $query = $this->db->select('channel_name, channel_id')
                           ->where_in('channel_name', $channels)
                           ->get('channels');

        if ($query->num_rows() > 0)
        {
            $channel_ids = array();
            foreach ($query->result_array() as $row)
            {
                $channel_ids[] = $row['channel_id'];

                // cache
                self::$_cache['channels'][$row['channel_name']] = $row['channel_id'];
            }  

            return $channel_ids;
        }
        else
        {
            return FALSE;
        }
    }

    /**
     * Get a channel id from a name
     *
     * @param   array $channel_id
     * @access  public
     * @return  integer/bool
     */
    public function get_channel_id($channel)
    {
        if ( ! isset(self::$_cache['channels'][$channel]))
        {
             $query = $this->db->select('channel_id')
                               ->where('channel_name', $channel)
                               ->limit(1)
                               ->get('channels');

            if ($query->num_rows() > 0)
            {
                $row = $query->row();
                self::$_cache['channels'][$channel] = $row->channel_id;
            }
            else
            {
                self::$_cache['channels'][$channel] = FALSE;
            }
        }

        return self::$_cache['channels'][$channel];
    }


    /**
     * Hydrate an array of entries
     *
     * @param   bool $future
     * @param   array $entry_ids
     * @access  public
     * @return  array/bool
     */
    public function hydrate_entries($entry_ids, $order_by='entry_date', $sort='desc', $limit=100, $offset=0, $fixed_order=FALSE, $future=FALSE)
    {
        $now = ee()->localize->now;
        $entries = array();

        // get a map of custom fields
        $fields = $this->_get_custom_fields();

        ee()->db->from('channel_titles ct')
                ->join('channel_data cd', 'cd.entry_id = ct.entry_id')
                ->where('ct.status', 'open')
                ->where("(ct.expiration_date = 0 OR ct.expiration_date > {$now})")
                ->where_in('ct.entry_id', $entry_ids);

        // don't show future entries?
        if (FALSE === $future)
        {
            ee()->db->where('entry_date <', $now);
        } 

        // apply sort/order
        if (FALSE === $fixed_order)
        {
            // map custom field names
            if (FALSE !== ($field_name = array_search($order_by, $fields)))
            {
                $order_by = $field_name;
            }
            ee()->db->order_by($order_by, $sort);
        }
        else
        {
            ee()->db->_protect_identifiers = FALSE; // otherwise CI escapes the following
            $fixed_order = implode(",", array_map( array($this->db, 'escape'), $fixed_order));
            ee()->db->order_by('FIELD ( ct.entry_id, ' . $fixed_order . ')');
        }

        // limit/offset
        ee()->db->limit($limit, $offset);

        $query = ee()->db->get();

        // re-protect identifiers
        if ($fixed_order)
        {
            ee()->db->_protect_identifiers = TRUE;
        }

        if ($query->num_rows() > 0)
        {
            $result = $query->result_array();
            
            $count = 1;
            foreach($result as $entry)
            {
                $data = array();

                // counts
                $data['count'] = $count;
                $data['absolute_count'] = $offset + $count;
                ++$count;

                // map custom fields
                foreach ($entry as $field => $val)
                {
                    if (isset($fields[$field]))
                    {
                        $data[$fields[$field]] = $val; 
                    }
                    elseif( ! strncmp($field, 'field_ft', 8) == 0 && ! strncmp($field, 'field_dt', 8) == 0 )
                    {
                        $data[$field] = $val;
                    }
                }
                $entries[] = $data;   
            }
        }

        return $entries;
    } 

    /**
     * Get absolute count of open entries for a given array of entry ids
     *
     * @param   bool $future
     * @param   array $entry_ids
     * @access  public
     * @return  array/bool
     */
    public function count_entries($entry_ids, $future=FALSE)
    {
        $now = ee()->localize->now;

        ee()->db->from('channel_titles ct')
                ->join('channel_data cd', 'cd.entry_id = ct.entry_id')
                ->where('ct.status', 'open')
                ->where("(ct.expiration_date = 0 OR ct.expiration_date > {$now})")
                ->where_in('ct.entry_id', $entry_ids);

        // don't show future entries?
        if (FALSE === $future)
        {
            ee()->db->where('entry_date <', $now);
        }

        return ee()->db->count_all_results();
    }

    /**
     * Maps field ids to field fieldnames
     *
     * @access  private
     * @return  array
     */

    private function _get_custom_fields()
    {  
        if ( empty(self::$_cache['fields']))
        {
            $fields = array();

            $query = ee()->db->select('field_id, field_name, field_type')
                             ->get('channel_fields');

            if ($query->num_rows() > 0)
            {   
                foreach($query->result() as $field)
                {
                   $fields['field_id_'.$field->field_id] = $field->field_name;
                }
            }  

            self::$_cache['fields'] = $fields;
        } 

        return self::$_cache['fields'];          
    }


    /**
     * _clean_str
     *
     * @param   str     string to clean
     * @access  private
     * @return  str
     */

    private function _clean_str( $str = '' )
    {
        ee()->load->helper(array('text', 'security'));

        $not_allowed = array('$', '?', ')', '(', '!', '<', '>', '/');

        $str = str_replace($not_allowed, '', $str);

        $str    = ( $this->preference('convert_case') != 'n') ?
                    $this->_strtolower($str): $str;

        if (ee()->config->item('auto_convert_high_ascii') == 'y')
        {
            ee()->load->helper('text');

            $str = ascii_to_entities($str);
        }

        return $str = ee()->security->xss_clean( $str );
    }
 
}

/* End of file resistor_model.php */
/* Location: ./system/expressionengine/third_party/resistor/models/resistor_model.php */