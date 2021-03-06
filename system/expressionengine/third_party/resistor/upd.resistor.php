<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Efficient multifaceted navigation using Low Search
 *
 * @package             Resistor
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2015 Hallmark Design
 * @link                http://hallmark-design.co.uk
 */

class Resistor_upd {
    
    public $name    = 'Resistor';
    public $version = '1.0.1';
    
    /**
     * Stash_upd
     * 
     * @access  public
     * @return  void
     */
    public function __construct()
    {
        $this->EE = get_instance();
    }
    
    /**
     * install
     * 
     * @access  public
     * @return  void
     */
    public function install()
    {   
        $sql = array();
        
        // install module 
        $this->EE->db->insert(
            'modules',
            array(
                'module_name' => $this->name,
                'module_version' => $this->version, 
                'has_cp_backend' => 'n',
                'has_publish_fields' => 'n'
            )
        );
        
        return TRUE;
    }
    
    /**
     * uninstall
     * 
     * @access  public
     * @return  void
     */
    public function uninstall()
    {
        $query = $this->EE->db->get_where('modules', array('module_name' => $this->name));
        
        if ($query->row('module_id'))
        {
            $this->EE->db->delete('module_member_groups', array('module_id' => $query->row('module_id')));
        }

        $this->EE->db->where('module_name', 'Wires')->delete('modules');

        return TRUE;
    }
    
    /**
     * update
     * 
     * @access  public
     * @param   mixed $current = ''
     * @return  void
     */
    public function update($current = '')
    {
        if ($current == '' OR version_compare($current, $this->version) === 0)
        {
            // up to date
            return FALSE;
        }

        // update version number
        return TRUE; 
    }
}

/* End of file upd.resistor.php */
/* Location: ./system/expressionengine/third_party/resistor/upd.resistor.php */