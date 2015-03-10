# Resistor

* Author: [Mark Croxton](http://hallmark-design.co.uk/)

## Version 1.0.0 (beta)

* Requires: [ExpressionEngine](https://ellislab.com/expressionengine), [Stash](https://github.com/croxton/stash), [Low Search](http://gotolow.com/addons/low-search), [Wires](https://github.com/croxton/Wires). 
* Optional: [Playa](https://devot-ee.com/add-ons/playa), [Solspace Tag](http://www.solspace.com/software/detail/tag/), [Taxonomy](https://devot-ee.com/add-ons/taxonomy), [Tax Playa](https://github.com/croxton/tax_playa)

## Description

Resistor is a subclass of Low Search that allows you to generate links for drill-down style entry filtering (multifaceted navigation). It is designed to be used in conjunction with [Wires](https://github.com/croxton/Wires).

## Features

* All the features of Low Search plus multi-pass filtering (2 passes currently supported), permitting combinations of AND / OR filtering for the same filter.
* Show remaining filter options for a given resultset and already-selected filters, together with the counts.

## Installation

1. [Download Wires](https://github.com/croxton/Resistor/archive/master.zip) and un-zip
2. Move the folder 'resistor' into ./system/expressionengine/third_party/
3. Edit ./system/epxressionengine/third_party/low_search/libraries/Low_search_filters.php and add this function:

    // --------------------------------------------------------------------

    /**
     * Set entry ids
     *
     * @access     public
     * @param      array
     * @return     null
     */
    public function set_entry_ids($entry_ids)
    {
        $this->_entry_ids = $entry_ids;
    }

## Caveats

I don't have time to provide support for this add-on. While it has the potential to save experienced EE developers a huge amount of time and effort, it is equally likely to frustrate the inexperienced and drive them to drink. *Please* don't use it unless you know what you're doing. You have been warned!

## {exp:resistor:results}

Use in place of {exp:low_search:results}, with these additional parameters:

### `fallback_when_empty=""` 
When no filters are selected, you may wish to show all entries rather than no results. Use this parameter to list the filters you are using, and when ALL of them are empty the fallback is shown. When any one of them has a value, only results matching the filter will be shown.

### `refine:[filter]=""` 
Specify second-pass filters by prefixing with `refine:`.


## Example search form

	{exp:wires:connect 
		id="site_search" 
		form="no" 
		url="{base_url}products/{category}?keywords={keywords}"
		prefix="search"

		{!-- 'keywords' --}
		+keywords="single"
	    +keywords:default_in=""
	    +keywords:default_out=""

	    {!-- 'category' --}
	    +category="multiple"
	    +category:default_in="any"
	    +category:default_out=""
	    +category:match="#^[0-9\|]+$#"
	    +category:delimiter_in="-or-"
	    +category:delimiter_out="|"
	}
		{exp:resistor:results 
	        collection="products"
	        channel="products"
	        keywords = "{search}"
	        category = "{category}"
	        orderby="cf_price"
	        sort="asc"
	        limit="10"
	        status="open"
	        disable="member_data"
	        fallback_when_empty="keywords|category"
	    }
	        {if search:no_results}
	            No results
	        {/if}
	        {if search:count==1}
	        <table class="results">
	            <thead>
	                <tr>
	                    <th>Product</th>
	                    <th>Price</th>
	                </tr>
	            </thead>
	            <tbody>
	        {/if}
	                <tr class="results-row{search:switch='|-alt'}">
	                    <td><a href="{title_permalink='products'}">{title}</a></td>
	                    <td>{cf_price}</td>
	                </tr>
	        {if search:count==search:total_results}    
	            </tbody>
	        </table>
	        {/if}

	        {paginate}
			    {pagination_links}
			        <ul id="js-paging" class="pagination" role="menubar" aria-label="pagination">

			        {previous_page}
			            <li><a href="{pagination_url}" class="page-previous"><i class="icon icon-chevron-left"></i><span class="hide-until-m"> Previous</span></a></li>
			        {/previous_page}

			        {page}
			            <li><a href="{pagination_url}" class="page-no page-{pagination_page_number} {if current_page}active{/if}">{pagination_page_number}</a></li>
			        {/page}

			        {next_page}
			            <li><a href="{pagination_url}" class="page-next"><span class="hide-until-m">Next </span><i class="icon icon-chevron-right"></i></a></li>
			        {/next_page}

			        </ul>
			    {/pagination_links}
			{/paginate}

	        {exp:stash:set type="snippet" replace="no"}
			    {stash:results_from}{search:absolute_count}{/stash:results_from}
			    {stash:results_total}{search:total_results}{/stash:results_total}
			    {stash:results_absolute}{search:absolute_results}{/stash:results_absolute}
			{/exp:stash:set}

	    {/exp:resistor:results}

	{/exp:wires:connect}


## Show selected options & further refinements

	{exp:wires:connect id="site_search" prefix="inner"}

		{if results_from}

	    {!-- refine panel --}
	    <div class="refine">

	    	{!-- refine: selected --}
	        {if results_from > 0 AND (category OR keywords)}
	        <div class="refine-options refine--selected">

	            <h3 class="refine-section">You searched for:</h3>

	            <ul class="refine-filters">

	            	{!-- keyword --}
	                {if keywords}
	                <li class="active"><a rel="nofollow" title="keyword" href="/search"><i class="icon icon-remove-sign"></i> &ldquo;{keywords}&rdquo;</a></li>
	                {/if}

	                {!-- category --}
	                {if category}
	                {exp:resistor:categories group_id="1|2|3"}
	                    {if cat_id ~ '/(^|\|)'.category.'($|\|)/'}
	                        <li class="active"><a rel="nofollow" title="category" href="{exp:wires:url id='site_search' +category='{cat_id}' remove='yes'}"><i class="icon icon-remove-sign"></i> {cat_name}</a></li>
	                    {/if}
	                {/exp:resistor:categories}
	                {/if}
	            </ul>
	        </div>
	        {/if}

	        {!-- refine: options --}
	        {if category == ""}
	        <div class="refine-options">

				<h3 class="refine-section">Refine your search:</h3>

	            {!-- category --}
            	<h4 class="refine-title">Category</h4>
                <ul class="refine-filters">
                {exp:resistor:categories group_id="1|2|3" prefix="filter"}
                    {if "{filter:cat_id}" ~ '/(^|\|)'.category.'($|\|)/'}
                        <li class="active"><a rel="nofollow" href="{exp:wires:url id='site_search' +category='{filter:cat_id}' remove='yes'}"><span class="icon icon-remove-sign"></span> {filter:cat_name}</a></li>
                    {if:else}
                        <li><a rel="nofollow" href="{exp:wires:url id='site_search' +category='{filter:cat_id}'}"><span class="icon icon-circle-blank"></span> {filter:cat_name} ({filter:cnt})</a></li>
                    {/if}
                    {if filter:no_results}
                    	<li>No categories found</li>
                    {/if}
                {/exp:resistor:categories}
                </ul>

	       	</div>
	       	{/if}

	    </div>
		{/if}

	{/exp:wires:connect}

