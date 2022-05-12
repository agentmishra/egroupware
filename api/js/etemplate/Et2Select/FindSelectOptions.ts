/**
 * EGroupware eTemplate2 - Find select options
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package api
 * @link https://www.egroupware.org
 * @author Nathan Gray
 */

export interface SelectOption
{
	value : string;
	label : string;
	// Hover help text
	title? : string;
	// Related image or icon
	icon? : string;
}

/**
 * Find the select options for a widget, out of the many places they could be.
 *
 * This will give valid, correct array of SelectOptions.  It will check:
 * - sel_options ArrayMgr, taking into account namespaces and checking the root
 * - content ArrayMgr, looking for "options-<id>"
 * - passed options, used by specific select types
 *
 * @param {Et2Widget} widget to check for.  Should be some sort of select widget.
 * @param {object} attr_options Select options in attributes array
 * @param {SelectOption[]} options Known options, passed in if you've already got some.  Cached type options, for example.
 * @return {SelectOption[]} Select options, or empty array
 */
export function find_select_options(widget, attr_options?, options : SelectOption[] = []) : SelectOption[]
{
	let name_parts = widget.id.replace(/&#x5B;/g, '[').replace(/]|&#x5D;/g, '').split('[');

	let content_options : SelectOption[] = [];

	// Try to find the options inside the "sel-options"
	if(widget.getArrayMgr("sel_options"))
	{
		// Try first according to ID
		let options = widget.getArrayMgr("sel_options").getEntry(widget.id);
		// ID can get set to an array with 0 => ' ' - not useful
		if(options && (options.length == 1 && typeof options[0] == 'string' && options[0].trim() == '' ||
			// eg. autorepeated id "cat[3]" would pick array element 3 from cat
			typeof options.value != 'undefined' && typeof options.label != 'undefined' && widget.id.match(/\[\d+]$/)))
		{
			content_options = null;
		}
		else
		{
			content_options = options;
		}
		// We could wind up too far inside options if label,title are defined
		if(options && !isNaN(name_parts[name_parts.length - 1]) && options.label && options.title)
		{
			name_parts.pop();
			content_options = widget.getArrayMgr("sel_options").getEntry(name_parts.join('['));
			delete content_options["$row"];
		}

		// Select options tend to be defined once, at the top level, so try that
		if(!content_options || content_options.length == 0)
		{
			content_options = widget.getArrayMgr("sel_options").getRoot().getEntry(name_parts[name_parts.length - 1]);
		}

		// Try in correct namespace (inside a grid or something)
		if(!content_options || content_options.length == 0)
		{
			content_options = widget.getArrayMgr("sel_options").getEntry(name_parts[name_parts.length - 1]);
		}

		// Try name like widget[$row]
		if(name_parts.length > 1 && (!content_options || content_options.length == 0))
		{
			let pop_that = JSON.parse(JSON.stringify(name_parts));
			while(pop_that.length > 1 && (!content_options || content_options.length == 0))
			{
				let last = pop_that.pop();
				content_options = widget.getArrayMgr('sel_options').getEntry(pop_that.join('['));

				// Double check, might have found a normal parent namespace ( eg subgrid in subgrid[selectbox] )
				// with an empty entry for the selectbox.  If there were valid options here,
				// we would have found them already, and keeping this would result in the ID as an option
				if(content_options && !Array.isArray(content_options) && typeof content_options[last] != 'undefined' && content_options[last])
				{
					content_options = content_options[last];
				}
				else if(content_options)
				{
					// Check for real values
					for(let key in content_options)
					{
						if(!(isNaN(<number><unknown>key) && typeof content_options[key] === 'string' ||
							!isNaN(<number><unknown>key) && typeof content_options[key] === 'object' && typeof content_options[key]['value'] !== 'undefined'))
						{
							// Found a parent of some other namespace
							content_options = undefined;
							break;
						}
					}
				}
			}
		}

		// Maybe in a row, and options got stuck in ${row} instead of top level
		// not sure this code is still needed, as server-side no longer creates ${row} or {$row} for select-options
		let row_stuck = ['${row}', '{$row}'];
		for(let i = 0; i < row_stuck.length && (!content_options || content_options.length == 0); i++)
		{
			// perspectiveData.row in nm, data["${row}"] in an auto-repeat grid
			if(widget.getArrayMgr("sel_options").perspectiveData.row || widget.getArrayMgr("sel_options").data[row_stuck[i]])
			{
				var row_id = widget.id.replace(/[0-9]+/, row_stuck[i]);
				content_options = widget.getArrayMgr("sel_options").getEntry(row_id);
				if(!content_options || content_options.length == 0)
				{
					content_options = widget.getArrayMgr("sel_options").getEntry(row_stuck[i] + '[' + widget.id + ']');
				}
			}
		}
		if(attr_options && Object.keys(attr_options).length > 0 && content_options)
		{
			//content_options = jQuery.extend(true, {}, attr_options, content_options);
			content_options = [...attr_options, ...content_options];
		}
	}

	// Check whether the options entry was found, if not read it from the
	// content array.
	if(content_options && content_options.length > 0 && widget.getArrayMgr('content') != null)
	{
		if(content_options)
		{
			attr_options = content_options;
		}
		let content_mgr = widget.getArrayMgr('content');
		if(content_mgr)
		{
			// If that didn't work, check according to ID
			if(!content_options)
			{
				content_options = content_mgr.getEntry("options-" + widget.id);
			}
			// Again, try last name part at top level
			if(!content_options)
			{
				content_options = content_mgr.getRoot().getEntry("options-" + name_parts[name_parts.length - 1]);
			}
		}
	}

	// Default to an empty object
	if(content_options == null)
	{
		content_options = [];
	}

	// Include passed options, preferring any content options
	if(options.length || Object.keys(options).length > 0)
	{
		for(let i in content_options)
		{
			let value = typeof content_options[i] == 'object' && typeof content_options[i].value !== 'undefined' ? content_options[i].value : i;
			let added = false;

			// Override any existing
			for(let j in options)
			{
				if('' + options[j].value === '' + value)
				{
					added = true;
					options[j] = content_options[i];
					break;
				}
			}
			if(!added)
			{
				options.splice(parseInt(i), 0, content_options[i]);
			}
		}
		content_options = options;
	}

	// Clean up
	if(!Array.isArray(content_options) && typeof content_options === "object" && Object.values(content_options).length > 0)
	{
		let fixed_options = [];
		for(let key in <object>content_options)
		{
			let option = {value: key, label: content_options[key]}
			// This could be an option group - not sure we have any
			if(typeof option.label !== "string" && option.label)
			{
				// @ts-ignore Yes, option.label.label is not supposed to exist but that's what we're checking
				if(typeof option.label.label !== "undefined")
				{
					// @ts-ignore Yes, option.label.label is not supposed to exist but that's what we're checking
					option.label = option.label.label;
				}
			}
			fixed_options.push(option);
		}
		content_options = fixed_options;
	}
	return content_options;
}
