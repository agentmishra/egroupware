<?php
  /**************************************************************************\
  * phpGroupWare - Calendar                                                  *
  * http://www.phpgroupware.org                                              *
  * Based on Webcalendar by Craig Knudsen <cknudsen@radix.net>               *
  *          http://www.radix.net/~cknudsen                                  *
  * Modified by Mark Peters <skeeter@phpgroupware.org>                       *
  * --------------------------------------------                             *
  *  This program is free software; you can redistribute it and/or modify it *
  *  under the terms of the GNU General Public License as published by the   *
  *  Free Software Foundation; either version 2 of the License, or (at your  *
  *  option) any later version.                                              *
  \**************************************************************************/

	/* $Id$ */

	class bocalendar
	{
		var $public_functions = Array(
			'read_entries'	=> True,
			'read_entry'	=> True,
			'add_entry'	=> True,
			'delete_entry' => True,
			'update_entry'	=> True
		);

		var $debug = False;

		var $so;
		var $cached_events;
		var $repeating_events;
		var $datetime;
		var $day;
		var $month;
		var $year;
		var $prefs;

		var $owner;
		var $holiday_color;
		var $printer_friendly = False;

		var $cached_holidays;
		
		var $filter;
		var $cat_id;
		var $users_timeformat;
		
		var $modified;
		var $deleted;
		var $added;
		
		var $use_session = False;

		function bocalendar($session=0)
		{
			global $phpgw, $phpgw_info, $date, $year, $month, $day, $owner, $filter, $fcat_id, $friendly;

			$phpgw->nextmatchs = CreateObject('phpgwapi.nextmatchs');

			$this->grants = $phpgw->acl->get_grants('calendar');

			if($this->debug) { echo "Read Use_Session : (".$session.")<br>\n"; }

			if($session)
			{
				$this->read_sessiondata();
				$this->use_session = True;
			}

			if($this->debug)
			{
				echo "BO Filter : (".$this->filter.")<br>\n";
				echo "Owner : ".$this->owner."<br>\n";
			}
			
			if(isset($owner))
			{
				$this->owner = intval($owner);
			}
			elseif(!isset($this->owner) || !$this->owner)
			{
				$this->owner = $phpgw_info['user']['account_id'];
			}

			$this->prefs['common']    = $phpgw_info['user']['preferences']['common'];
			$this->prefs['calendar']    = $phpgw_info['user']['preferences']['calendar'];

			if ($this->prefs['common']['timeformat'] == '12')
			{
				$this->users_timeformat = 'h:i a';
			}
			else
			{
				$this->users_timeformat = 'H:i';
			}

			$this->holiday_color = (substr($phpgw_info['theme']['bg07'],0,1)=='#'?'':'#').$phpgw_info['theme']['bg07'];

			if($friendly == 1)
			{
				$this->printer_friendly = True;
			}

			if(isset($filter))   { $this->filter = $filter; }
			if(isset($cat_id))  { $this->cat_id = $cat_id; }

			if(!isset($this->filter))
			{
				$this->filter = ' '.$this->prefs['calendar']['defaultfilter'].' ';
			}

			if(isset($date))
			{
				$this->year = intval(substr($date,0,4));
				$this->month = intval(substr($date,4,2));
				$this->day = intval(substr($date,6,2));
			}
			else
			{
				if(isset($year))
				{
					$this->year = $year;
				}
				elseif($this->year == 0)
				{
					$this->year = date('Y',time());
				}
				if(isset($month))
				{
					$this->month = $month;
				}
				elseif($this->month == 0)
				{
					$this->month = date('m',time());
				}
				if(isset($day))
				{
					$this->day = $day;
				}
				elseif($this->day == 0)
				{
					$this->day = date('d',time());
				}
			}
			
			$this->so = CreateObject('calendar.socalendar',$this->owner,$this->filter,$this->cat_id);
			$this->datetime = $this->so->datetime;
			
			if($this->debug)
			{
				echo "BO Filter : (".$this->filter.")<br>\n";
				echo "Owner : ".$this->owner."<br>\n";
			}
		}

		function save_sessiondata($data)
		{
			if ($this->use_session)
			{
				global $phpgw;
				if($this->debug) { echo '<br>Save:'; _debug_array($data); }
				$phpgw->session->appsession('session_data','calendar',$data);
			}
		}

		function read_sessiondata()
		{
			global $phpgw;

			$data = $phpgw->session->appsession('session_data','calendar');
			if($this->debug) { echo '<br>Read:'; _debug_array($data); }

			$this->filter = $data['filter'];
			$this->cat_id = $data['cat_id'];
			$this->owner  = intval($data['owner']);
			$this->year   = intval($data['year']);
			$this->month  = intval($data['month']);
			$this->day    = intval($data['day']);
		}

		function read_entry($id)
		{
			if($this->check_perms(PHPGW_ACL_READ))
			{
				return $this->so->read_entry($id);
			}
		}

		function add_entry($event)
		{
			if($this->check_perms(PHPGW_ACL_ADD))
			{
				$this->so->add_entry($event);
				$this->send_update(MSG_ADDED,$event->participants,'',$this->get_cached_event());
			}
		}

		function update_entry($event)
		{
			if($this->check_perms(PHPGW_ACL_EDIT))
			{
				if($event->id != 0)
				{
					$new_event = $event;
					$old_event = $this->read_entry($new_event->id);
					$this->prepare_recipients($new_event,$old_event);
				}
				$this->so->add_entry($event);
			}
		}

		function delete_entry($id)
		{
			if($this->check_perms(PHPGW_ACL_DELETE))
			{
				$this->so->delete_entry($id);
			}
		}

		function expunge()
		{
			if($this->check_perms(PHPGW_ACL_DELETE))
			{
				for($i=0;$i<count($this->so->cal->deleted_events);$i++)
				{
					$event_id = $this->so->cal->deleted_events[$i];
					$event = $this->so->read_entry($event_id);
					$this->send_update(MSG_DELETED,$event->participants,$event);
				}
				$this->so->expunge();
			}
		}
		/* Private functions */

		function read_holidays()
		{
			$holiday = CreateObject('calendar.boholiday',$this->year,$this->owner);
			$this->cached_holidays = $holiday->read_holiday();
			unset($holiday);
		}

		function can_user_edit($event)
		{
			$can_edit = False;
		
			if(($event->owner == $this->owner) && ($this->check_perms(PHPGW_ACL_EDIT) == True))
			{
				if($event->public != True)
				{
					if($this->check_perms(PHPGW_ACL_PRIVATE) == True)
					{
						$can_edit = True;
					}
				}
				else
				{
					$can_edit = True;
				}
			}
			return $can_edit;
		}

		function fix_update_time(&$time_param)
		{
			global $phpgw_info;
			
			if ($this->prefs['common']['timeformat'] == '12')
			{
				if ($time_param[ampm] == 'pm')
				{
					if ($time_param[hour] <> 12)
					{
						$time_param[hour] += 12;
					}
				}
				elseif ($time_param[ampm] == 'am')
				{
					if ($time_param[hour] == 12)
					{
						$time_param[hour] -= 12;
					}
				}
		
				if($time_param[hour] > 24)
				{
					$time_param[hour] -= 12;
				}
			}
		}

		function validate_update($event)
		{
			$error = 0;
			// do a little form verifying
			if ($event->title == '')
			{
				$error = 40;
			}
			elseif (($this->datetime->time_valid($event->start->hour,$event->start->min,0) == False) || ($this->datetime->time_valid($event->end->hour,$event->end->min,0) == False))
			{
				$error = 41;
			}
			elseif (($this->datetime->date_valid($event->start->year,$event->start->month,$event->start->mday) == False) || ($this->datetime->date_valid($event->end->year,$event->end->month,$event->end->mday) == False) || ($this->datetime->date_compare($event->start->year,$event->start->month,$event->start->mday,$event->end->year,$event->end->month,$event->end->mday) == 1))
			{
				$error = 42;
			}
			elseif ($this->datetime->date_compare($event->start->year,$event->start->month,$event->start->mday,$event->end->year,$event->end->month,$event->end->mday) == 0)
			{
				if ($this->datetime->time_compare($event->start->hour,$event->start->min,0,$event->end->hour,$event->end->min,0) == 1)
				{
					$error = 42;
				}
			}
			return $error;
		}

		function overlap($starttime,$endtime,$participants,$owner=0,$id=0)
		{
			global $phpgw, $phpgw_info;

			$retval = Array();
			$ok = False;

			if($starttime == $endtime && $phpgw->common->show_date($starttime,'Hi') == 0)
			{
				$endtime = mktime(23,59,59,$phpgw->common->show_date($starttime,'m'),$phpgw->common->show_date($starttime,'d') + 1,$phpgw->common->show_date($starttime,'Y')) - $this->datetime->tz_offset;
			}

			$sql = 'AND ((('.$starttime.' <= phpgw_cal.datetime) AND ('.$endtime.' >= phpgw_cal.datetime) AND ('.$endtime.' <= phpgw_cal.edatetime)) '
					.  'OR (('.$starttime.' >= phpgw_cal.datetime) AND ('.$starttime.' < phpgw_cal.edatetime) AND ('.$endtime.' >= phpgw_cal.edatetime)) '
					.  'OR (('.$starttime.' <= phpgw_cal.datetime) AND ('.$endtime.' >= phpgw_cal.edatetime)) '
					.  'OR (('.$starttime.' >= phpgw_cal.datetime) AND ('.$endtime.' <= phpgw_cal.edatetime))) ';

			if(count($participants) > 0)
			{
				$p_g = '';
				if(count($participants))
				{
					$users = Array();
					while(list($user,$status) = each($participants))
					{
						$users[] = $user;
					}
					if($users)
					{
						$p_g .= 'phpgw_cal_user.cal_login in ('.implode(',',$users).')';
					}
				}
				if($p_g)
				{
					$sql .= ' AND (' . $p_g . ')';
				}
			}
      
			if($id)
			{
				$sql .= ' AND phpgw_cal.cal_id <> '.$id;
			}

			$sql .= ' ORDER BY phpgw_cal.datetime ASC, phpgw_cal.edatetime ASC, phpgw_cal.priority ASC';

			$events = $this->so->get_event_ids(False,$sql);
			if($events == False)
			{
				return false;
			}
		
			$db2 = $phpgw->db;

			for($i=0;$i<count($events);$i++)
			{
				$db2->query('SELECT recur_type FROM phpgw_cal_repeats WHERE cal_id='.$events[$i],__LINE__,__FILE__);
				if($db2->num_rows() == 0)
				{
					$retval[] = $events[$i];
					$ok = True;
				}
				else
				{
					$db2->next_record();
					if($db2->f('recur_type') <> MCAL_RECUR_MONTHLY_MDAY)
					{
						$retval[] = $events[$i];
						$ok = True;
					}
				}
			}
			if($ok == True)
			{
				return $retval;
			}
			else
			{
				return False;
			}
		}

		function check_perms($needed,$user=0)
		{
			if($user == 0)
			{
				return ($this->grants[$this->owner] & $needed);
			}
			else
			{
				return ($this->grants[$user] & $needed);
			}
		}

		function get_fullname($accountid)
		{
			global $phpgw;

			$account_id = get_account_id($accountid);
			if($phpgw->accounts->exists($account_id) == False)
			{
				return False;
			}
			$db = $phpgw->db;
			$db->query('SELECT account_lid,account_lastname,account_firstname FROM phpgw_accounts WHERE account_id='.$account_id,__LINE__,__FILE__);
			if($db->num_rows())
			{
				$db->next_record();
				$fullname = $db->f('account_lid');
				$lname = $db->f('account_lastname');
				$fname = $db->f('account_firstname');
				if($lname && $fname)
				{
					$fullname = $lname.', '.$fname;
				}
				return $fullname;
			}
			else
			{
				return False;
			}
		}

		function display_status($user_status)
		{
			if(@$this->prefs['calendar']['display_status'])
			{
				return ' ('.$user_status.')';
			}
			else
			{
				return '';
			}
		}

		function get_long_status($status_short)
		{
			switch ($status_short)
			{
				case 'A':
					$status = lang('Accepted');
					break;
				case 'R':
					$status = lang('Rejected');
					break;
				case 'T':
					$status = lang('Tentative');
					break;
				case 'U':
					$status = lang('No Response');
					break;
			}
			return $status;
		}

		function is_private($event,$owner)
		{
			global $phpgw, $phpgw_info;

			if($owner == 0) { $owner = $this->owner; }
			if ($owner == $phpgw_info['user']['account_id'] || ($this->check_perms(PHPGW_ACL_PRIVATE,$owner) && $event->public==0) || ($event->public == 1))
			{
				$is_private  = False;
			}
			elseif($event->public == 0)
			{
				$is_private = True;
			}
			elseif($event->public == 2)
			{
				$is_private = True;
				$groups = $phpgw->accounts->memberships($owner);
				while ($group = each($groups))
				{
					if (strpos(' '.implode($event->groups,',').' ',$group[1]['account_id']))
					{
						$is_private = False;
					}
				}
			}
			else
			{
				$is_private = False;
			}

			return $is_private;
		}

		function get_short_field($event,$is_private=True,$field='')
		{
			if ($is_private)
			{
				$str = 'private';
			}
			elseif (strlen($event->$field) > 19)
			{
				$str = substr($event->$field, 0 , 19) . '...';
			}
			else
			{
				$str = $event->$field;
			}

			return $str;
		}

		function normalizeminutes(&$minutes)
		{
			$hour = 0;
			$min = intval($minutes);
			if($min >= 60)
			{
				$hour += $min / 60;
				$min %= 60;
			}
			settype($minutes,'integer');
			$minutes = $min;
			return $hour;
		}

		function splittime($time,$follow_24_rule=True)
		{
			global $phpgw_info;

			$temp = array('hour','minute','second','ampm');
			$time = strrev($time);
			$second = intval(strrev(substr($time,0,2)));
			$minute = intval(strrev(substr($time,2,2)));
			$hour   = intval(strrev(substr($time,4)));
			$hour += $this->normalizeminutes(&$minute);
			$temp['second'] = $second;
			$temp['minute'] = $minute;
			$temp['hour']   = $hour;
			$temp['ampm']   = '  ';
			if($follow_24_rule == True)
			{
				if ($this->prefs['common']['timeformat'] == '24')
				{
					return $temp;
				}
		
				$temp['ampm'] = 'am';
		
				if ((int)$temp['hour'] > 12)
				{
					$temp['hour'] = (int)((int)$temp['hour'] - 12);
					$temp['ampm'] = 'pm';
   		   }
      		elseif ((int)$temp['hour'] == 12)
	      	{
					$temp['ampm'] = 'pm';
				}
			}
			return $temp;
		}

		function build_time_for_display($fixed_time)
		{
			global $phpgw_info;
		
			$time = $this->splittime($fixed_time);
			$str = '';
			$str .= $time['hour'].':'.((int)$time['minute']<=9?'0':'').$time['minute'];
		
			if ($this->prefs['common']['timeformat'] == '12')
			{
				$str .= ' ' . $time['ampm'];
			}
		
			return $str;
		}
	
		function sort_event($event,$date)
		{
			$inserted = False;
			if($this->cached_events[$date])
			{
				for($i=0;$i<count($this->cached_events[$date]);$i++)
				{
					$events = $this->cached_events[$date][$i];
					$events_id = $events->id;
					$event_id = $event->id;
					if($events->id == $event->id)
					{
						$inserted = True;
						break;
					}
					$year = substr($date,0,4);
					$month = substr($date,4,2);
					$day = substr($date,6,2);
					if(date('Hi',mktime($event->start->hour,$event->start->min,$event->start->sec,$month,$day,$year)) < date('Hi',mktime($events->start->hour,$events->start->min,$events->start->sec,$month,$day,$year)))
					{
						for($j=count($this->cached_events[$date]);$j>=$i;$j--)
						{
							$this->cached_events[$date][$j + 1] = $this->cached_events[$date][$j];
						}
						$inserted = True;
						$this->cached_events[$date][$j] = $event;
						break;
					}
				}
			}
			if(!$inserted)
			{
				$this->cached_events[$date][] = $event;
			}					
		}

		function check_repeating_events($datetime)
		{
			global $phpgw, $phpgw_info;

			@reset($this->repeating_events);
			$search_date_full = date('Ymd',$datetime);
			$search_date_year = date('Y',$datetime);
			$search_date_month = date('m',$datetime);
			$search_date_day = date('d',$datetime);
			$search_date_dow = date('w',$datetime);
			$search_beg_day = mktime(0,0,0,$search_date_month,$search_date_day,$search_date_year);
			$repeated = $this->repeating_events;
			$r_events = count($repeated);
			for ($i=0;$i<$r_events;$i++)
			{
				$rep_events = $this->repeating_events[$i];
				$id = $rep_events->id;
				$event_beg_day = mktime(0,0,0,$rep_events->start->month,$rep_events->start->mday,$rep_events->start->year);
				if($rep_events->recur_enddate->month != 0 && $rep_events->recur_enddate->mday != 0 && $rep_events->recur_enddate->year != 0)
				{
					$event_recur_time = mktime($rep_events->recur_enddate->hour,$rep_events->recur_enddate->min,$rep_events->recur_enddate->sec,$rep_events->recur_enddate->month,$rep_events->recur_enddate->mday,$rep_events->recur_enddate->year);
				}
				else
				{
					$event_recur_time = mktime(0,0,0,1,1,2030);
				}
				$end_recur_date = date('Ymd',$event_recur_time);
				$full_event_date = date('Ymd',$event_beg_day);
			
				// only repeat after the beginning, and if there is an rpt_end before the end date
				if (($search_date_full > $end_recur_date) || ($search_date_full < $full_event_date))
				{
					continue;
				}

				if ($search_date_full == $full_event_date)
				{
					$this->sort_event($rep_events,$search_date_full);
					continue;
				}
				else
				{				
					$freq = $rep_events->recur_interval;
					$type = $rep_events->recur_type;
					switch($type)
					{
						case MCAL_RECUR_DAILY:
							if (floor(($search_beg_day - $event_beg_day)/86400) % $freq)
							{
								continue;
							}
							else
							{
								$this->sort_event($rep_events,$search_date_full);
							}
							break;
						case MCAL_RECUR_WEEKLY:
							if (floor(($search_beg_day - $event_beg_day)/604800) % $freq)
							{
								continue;
							}
							$check = 0;
							switch($search_date_dow)
							{
								case 0:
									$check = MCAL_M_SUNDAY;
									break;
								case 1:
									$check = MCAL_M_MONDAY;
									break;
								case 2:
									$check = MCAL_M_TUESDAY;
									break;
								case 3:
									$check = MCAL_M_WEDNESDAY;
									break;
								case 4:
									$check = MCAL_M_THURSDAY;
									break;
								case 5:
									$check = MCAL_M_FRIDAY;
									break;
								case 6:
									$check = MCAL_M_SATURDAY;
									break;
							}
							if ($rep_events->recur_data & $check)
							{
								$this->sort_event($rep_events,$search_date_full);
							}
							break;
						case MCAL_RECUR_MONTHLY_WDAY:
							if ((($search_date_year - $rep_events->start->year) * 12 + $search_date_month - $rep_events->start->month) % $freq)
							{
								continue;
							}
	  
							if (($this->datetime->day_of_week($rep_events->start->year,$rep_events->start->month,$rep_events->start->mday) == $this->datetime->day_of_week($search_date_year,$search_date_month,$search_date_day)) &&
								(ceil($rep_events->start->mday/7) == ceil($search_date_day/7)))
							{
								$this->sort_event($rep_events,$search_date_full);
							}
							break;
						case MCAL_RECUR_MONTHLY_MDAY:
							if ((($search_date_year - $rep_events->start->year) * 12 + $search_date_month - $rep_events->start->month) % $freq)
							{
								continue;
							}
							if ($search_date_day == $rep_events->start->mday)
							{
								$this->sort_event($rep_events,$search_date_full);
							}
							break;
						case MCAL_RECUR_YEARLY:
							if (($search_date_year - $rep_events->start->year) % $freq)
							{
								continue;
							}
							if (date('dm',$datetime) == date('dm',$event_beg_day))
							{
								$this->sort_event($rep_events,$search_date_full);
							}
							break;
					}
				}
			}	// end for loop
		}	// end function

		function store_to_cache($syear,$smonth,$sday,$eyear=0,$emonth=0,$eday=0)
		{
			global $phpgw, $phpgw_info;

			if($this->debug)
			{
				echo "Start Date : ".sprintf("%04d%02d%02d",$syear,$smonth,$sday)."<br>\n";
			}

			if(!$eyear && !$emonth && !$eday)
			{
				$edate = mktime(23,59,59,$smonth + 1,$sday + 1,$syear);
				$eyear = date('Y',$edate);
				$emonth = date('m',$edate);
				$eday = date('d',$edate);
			}
			else
			{
				if(!$eyear)
				{
					$eyear = $syear;
				}
				if(!$emonth)
				{
					$emonth = $smonth + 1;
				}
				if(!$eday)
				{
					$eday = $sday + 1;
				}
				$edate = mktime(23,59,59,$emonth,$eday,$eyear);
			}
			
			$cached_event_ids = $this->so->list_events($syear,$smonth,$sday,$eyear,$emonth,$eday);
			$cached_event_ids_repeating = $this->so->list_repeated_events($syear,$smonth,$sday,$eyear,$emonth,$eday);

			$c_cached_ids = count($cached_event_ids);
			$c_cached_ids_repeating = count($cached_event_ids_repeating);

			if($this->debug)
			{
				echo "events cached : $c_cached_ids : for : ".sprintf("%04d%02d%02d",$syear,$smonth,$sday)."<br>\n";
				echo "repeating events cached : $c_cached_ids_repeating : for : ".sprintf("%04d%02d%02d",$syear,$smonth,$sday)."<br>\n";
			}

			$this->cached_events = Array();
			
			if($c_cached_ids == 0 && $c_cached_ids_repeating == 0)
			{
				return;
			}

			$this->cached_events = Array();
			if($c_cached_ids)
			{
				for($i=0;$i<$c_cached_ids;$i++)
				{
					$event = $this->so->read_entry($cached_event_ids[$i]);
					$starttime = mktime($event->start->hour,$event->start->min,$event->start->sec,$event->start->month,$event->start->mday,$event->start->year);
					$endtime = mktime($event->end->hour,$event->end->min,$event->end->sec,$event->end->month,$event->end->mday,$event->end->year);
					$this->cached_events[date('Ymd',$starttime)][] = $event;
					if($this->cached_events[date('Ymd',$endtime)][count($this->cached_events[date('Ymd',$starttime)]) - 1] != $event)
					{
						$this->cached_events[date('Ymd',$endtime)][] = $event;
					}
				}
			}

			$this->repeating_events = Array();
			if($c_cached_ids_repeating)
			{
				for($i=0;$i<$c_cached_ids_repeating;$i++)
				{
					$this->repeating_events[$i] = $this->so->read_entry($cached_event_ids_repeating[$i]);
				}
				$edate -= $this->datetime->tz_offset;
				for($date=mktime(0,0,0,$smonth,$sday,$syear) - $this->datetime->tz_offset;$date<$edate;$date += (60 * 60 * 24))
				{
					$this->check_repeating_events($date);
				}
			}
		}

		/* Begin Appsession Data */
		function store_to_appsession($event)
		{
			global $phpgw;
			$phpgw->session->appsession('entry','calendar',$event);
		}

		function restore_from_appsession()
		{
			global $phpgw;
			$this->event_init();
			$event = unserialize(str_replace('O:8:"stdClass"','O:13:"calendar_time"',serialize($phpgw->session->appsession('entry','calendar'))));
			$this->so->cal->event = $event;
			return $event;
		}
		/* End Appsession Data */

		/* Begin of SO functions */
		function get_cached_event()
		{
			return $this->so->get_cached_event();
		}
		
		function add_attribute($var,$value)
		{
			$this->so->add_attribute($var,$value);
		}

		function event_init()
		{
			$this->so->event_init();
		}

		function set_start($year,$month,$day=0,$hour=0,$min=0,$sec=0)
		{
			$this->so->set_start($year,$month,$day,$hour,$min,$sec);
		}

		function set_end($year,$month,$day=0,$hour=0,$min=0,$sec=0)
		{
			$this->so->set_end($year,$month,$day,$hour,$min,$sec);
		}

		function set_title($title='')
		{
			$this->so->set_title($title);
		}

		function set_description($description='')
		{
			$this->so->set_description($description);
		}

		function set_class($class)
		{
			$this->so->set_class($class);
		}

		function set_category($category='')
		{
			$this->so->set_category($category);
		}

		function set_alarm($alarm)
		{
			$this->so->set_alarm($alarm);
		}

		function set_recur_none()
		{
			$this->so->set_recur_none();
		}

		function set_recur_daily($year,$month,$day,$interval)
		{
			$this->so->set_recur_daily($year,$month,$day,$interval);
		}

		function set_recur_weekly($year,$month,$day,$interval,$weekdays)
		{
			$this->so->set_recur_weekly($year,$month,$day,$interval,$weekdays);
		}

		function set_recur_monthly_mday($year,$month,$day,$interval)
		{
			$this->so->set_recur_monthly_mday($year,$month,$day,$interval);
		}

		function set_recur_monthly_wday($year,$month,$day,$interval)
		{
			$this->so->set_recur_monthly_wday($year,$month,$day,$interval);
		}

		function set_recur_yearly($year,$month,$day,$interval)
		{
			$this->so->set_recur_yearly($year,$month,$day,$interval);
		}
		/* End of SO functions */

		function set_week_array($startdate,$cellcolor,$weekly)
		{
			global $phpgw, $phpgw_info;

			$today = date('Ymd',time());
			for ($j=0;$j<7;$j++)
			{
				$date = $this->datetime->gmtdate($startdate + ($j * 86400));

				$holidays = $this->cached_holidays[$date['full']];
				if($weekly)
				{
					$cellcolor = $phpgw->nextmatchs->alternate_row_color($cellcolor);
				}
				
				$day_image = '';
				if($holidays)
				{
					$extra = ' bgcolor="'.$this->holiday_color.'"';
					$class = 'minicalhol';
					if ($date['full'] == $today)
					{
						$day_image = ' background="'.$phpgw->common->image('calendar','mini_day_block.gif').'"';
					}
				}
				elseif ($date['full'] != $today)
				{
					$extra = ' bgcolor="'.$cellcolor.'"';
					$class = 'minicalendar';
				}
				else
				{
					$extra = ' bgcolor="'.$phpgw_info['theme']['cal_today'].'"';
					$class = 'minicalendar';
					$day_image = ' background="'.$phpgw->common->image('calendar','mini_day_block.gif').'"';
				}

				if($this->printer_friendly && @$this->prefs['calendar']['print_black_white'])
				{
					$extra = '';
				}

				$new_event = False;
				if(!$this->printer_friendly && $this->check_perms(PHPGW_ACL_ADD))
				{
					$new_event = True;
				}
				$holiday_name = Array();
				if($holidays)
				{
					for($k=0;$k<count($holidays);$k++)
					{
						$holiday_name[] = $holidays[$k]['name'];
					}
				}
				$rep_events = $this->cached_events[$date['full']];
				$appts = False;
				if($rep_events)
				{
					$appts = True;
				}
				$week = '';
				if (!$j || ($j && substr($date['full'],6,2) == '01'))
				{
					$week = 'week ' .(int)((date('z',($startdate+(24*3600*4)))+7)/7);
				}
				$daily[$date['full']] = Array(
					'extra'		=> $extra,
					'new_event'	=> $new_event,
					'holidays'	=> $holiday_name,
					'appts'		=> $appts,
					'week'		=> $week,
					'day_image'	=> $day_image,
					'class'		=> $class
				);
			}

			if($this->debug)
			{
				$this->_debug_array($daily);
			}
			
			return $daily;
		}

		function prepare_matrix($interval,$increment,$part,$status,$fulldate)
		{
			global $phpgw;
			for($h=0;$h<24;$h++)
			{
				for($m=0;$m<$interval;$m++)
				{
					$index = (($h * 10000) + (($m * $increment) * 100));
					$time_slice[$index]['marker'] = '&nbsp';
					$time_slice[$index]['description'] = '';
				}
			}
			for($k=0;$k<count($this->cached_events[$fulldate]);$k++)
			{
				$event = $this->cached_events[$fulldate][$k];
				$eventstart = $this->datetime->localdates($event->datetime);
				$eventend = $this->datetime->localdates($event->edatetime);
				$start = ($eventstart['hour'] * 10000) + ($eventstart['minute'] * 100);
				$starttemp = $this->splittime("$start",False);
				$subminute = 0;
				for($m=0;$m<$interval;$m++)
				{
					$minutes = $increment * $m;
					if(intval($starttemp['minute']) > $minutes && intval($starttemp['minute']) < ($minutes + $increment))
					{
						$subminute = ($starttemp['minute'] - $minutes) * 100;
					}
				}
				$start -= $subminute;
				$end =  ($eventend['hour'] * 10000) + ($eventend['minute'] * 100);
				$endtemp = $this->splittime("$end",False);
				$addminute = 0;
				for($m=0;$m<$interval;$m++)
				{
					$minutes = ($increment * $m);
					if($endtemp['minute'] < ($minutes + $increment) && $endtemp['minute'] > $minutes)
					{
						$addminute = ($minutes + $increment - $endtemp['minute']) * 100;
					}
				}
				$end += $addminute;
				$starttemp = $this->splittime("$start",False);
				$endtemp = $this->splittime("$end",False);
// Do not display All-Day events in this free/busy time
				if((($starttemp['hour'] == 0) && ($starttemp['minute'] == 0)) && (($endtemp['hour'] == 23) && ($endtemp['minute'] == 59)))
				{
				}
				else
				{
					for($h=$starttemp['hour'];$h<=$endtemp['hour'];$h++)
					{
						$startminute = 0;
						$endminute = $interval;
						$hour = $h * 10000;
						if($h == intval($starttemp['hour']))
						{
							$startminute = ($starttemp['minute'] / $increment);
						}
						if($h == intval($endtemp['hour']))
						{
							$endminute = ($endtemp['minute'] / $increment);
						}
						$private = $this->is_private($event,$part);
						$time_display = $phpgw->common->show_date($eventstart['raw'],$this->users_timeformat).'-'.$phpgw->common->show_date($eventend['raw'],$this->users_timeformat);
						$time_description = '('.$time_display.') '.$this->get_short_field($event,$private,'title').$this->display_status($event->participants[$part]);
						for($m=$startminute;$m<=$endminute;$m++)
						{
							$index = ($hour + (($m * $increment) * 100));
							$time_slice[$index]['marker'] = '-';
							$time_slice[$index]['description'] = $time_description;
						}
					}
				}
			}
			return $time_slice;
		}

		function set_status($cal_id,$status)
		{
			$old_event = $this->so->read_entry($cal_id);
			switch($status)
			{
				case REJECTED:
					$this->send_update(MSG_REJECTED,$old_event->participants,$old_event);
					$this->so->set_status($cal_id,$status);
					break;
				case TENTATIVE:
					$this->send_update(MSG_TENTATIVE,$old_event->participants,$old_event);
					$this->so->set_status($cal_id,$status);
					break;
				case ACCEPTED:
					$this->send_update(MSG_ACCEPTED,$old_event->participants,$old_event);
					$this->so->set_status($cal_id,$status);
					break;
			}
			return True;
		}

		function send_update($msg_type,$participants,$old_event=False,$new_event=False)
		{

			global $phpgw, $phpgw_info;

			$db = $phpgw->db;
			$db->query("SELECT app_version FROM phpgw_applications WHERE app_name='calendar'",__LINE__,__FILE__);
			$db->next_record();
			$version = $db->f('app_version');
			unset($db);

			$phpgw_info['user']['preferences'] = $phpgw->common->create_emailpreferences($phpgw_info['user']['preferences']);
			$sender = $phpgw_info['user']['preferences']['email']['address'];

			$temp_tz_offset = $this->prefs['common']['tz_offset'];
			$temp_timeformat = $this->prefs['common']['timeformat'];
			$temp_dateformat = $this->prefs['common']['dateformat'];

			$tz_offset = ((60 * 60) * intval($temp_tz_offset));

			if($old_event != False)
			{
				$t_old_start_time = mktime($old_event->start->hour,$old_event->start->min,$old_event->start->sec,$old_event->start->month,$old_event->start->mday,$old_event->start->year);
				if($t_old_start_time < (time() - 86400))
				{
					return False;
				}
			}

			$temp_user = $phpgw_info['user'];

			if($this->owner != $temp_user['account_id'])
			{
				$user = $this->owner;
		
				$accounts = CreateObject('phpgwapi.accounts',$user);
				$phpgw_info['user'] = $accounts->read_repository();

				$pref = CreateObject('phpgwapi.preferences',$user);
				$phpgw_info['user']['preferences'] = $pref->read_repository();
			}
			else
			{
				$user = $phpgw_info['user']['account_id'];
			}

			$phpgw_info['user']['preferences'] = $phpgw->common->create_emailpreferences($phpgw_info['user']['preferences'],$user);

			$send = CreateObject('phpgwapi.send');

			switch($msg_type)
			{
				case MSG_DELETED:
					$action = 'Deleted';
					$event_id = $old_event->id;
					$msgtype = '"calendar";';
					break;
				case MSG_MODIFIED:
					$action = 'Modified';
					$event_id = $old_event->id;
					$msgtype = '"calendar"; Version="'.$version.'"; Id="'.$new_event->id.'"';
					break;
				case MSG_ADDED:
					$action = 'Added';
					$event_id = $new_event->id;
					$msgtype = '"calendar"; Version="'.$version.'"; Id="'.$new_event->id.'"';
					break;
				case MSG_REJECTED:
					$action = 'Rejected';
					$event_id = $old_event->id;
					$msgtype = '"calendar";';
					break;
				case MSG_TENTATIVE:
					$action = 'Tentative';
					$event_id = $old_event->id;
					$msgtype = '"calendar";';
					break;
				case MSG_ACCEPTED:
					$action = 'Tentative';
					$event_id = $old_event->id;
					$msgtype = '"calendar";';
					break;
			}

			if($old_event != False)
			{
				$old_event_datetime = $t_old_start_time - $this->datetime->tz_offset;
			}
		
			if($new_event != False)
			{
				$new_event_datetime = mktime($new_event->start->hour,$new_event->start->min,$new_event->start->sec,$new_event->start->month,$new_event->start->mday,$new_event->start->year) - $this->datetime->tz_offset;
			}

			while(list($userid,$statusid) = each($participants))
			{
				if(intval($userid) != $phpgw_info['user']['account_id'])
				{
//					echo "Msg Type = ".$msg_type."<br>\n";
//					echo "userid = ".$userid."<br>\n";
					$preferences = CreateObject('phpgwapi.preferences',intval($userid));
					$part_prefs = $preferences->read_repository();
					if(!isset($part_prefs['calendar']['send_updates']) || !$part_prefs['calendar']['send_updates'])
					{
						continue;
					}
					$part_prefs = $phpgw->common->create_emailpreferences($part_prefs,intval($userid));
					$to = $part_prefs['email']['address'];
//					echo "Email being sent to: ".$to."<br>\n";

					$phpgw_info['user']['preferences']['common']['tz_offset'] = $part_prefs['common']['tz_offset'];
					$phpgw_info['user']['preferences']['common']['timeformat'] = $part_prefs['common']['timeformat'];
					$phpgw_info['user']['preferences']['common']['dateformat'] = $part_prefs['common']['dateformat'];
				
					$new_tz_offset = ((60 * 60) * intval($phpgw_info['user']['preferences']['common']['tz_offset']));

					if($old_event != False)
					{
						$old_event_date = $phpgw->common->show_date($old_event_datetime);
					}
				
					if($new_event != False)
					{
						$new_event_date = $phpgw->common->show_date($new_event_datetime);
					}
				
					switch($msg_type)
					{
						case MSG_DELETED:
							$action_date = $old_event_date;
							$body = 'Your meeting scehduled for '.$old_event_date.' has been canceled';
							break;
						case MSG_MODIFIED:
							$action_date = $new_event_date;
							$body = 'Your meeting that had been scheduled for '.$old_event_date.' has been rescheduled to '.$new_event_date;
							break;
						case MSG_ADDED:
							$action_date = $new_event_date;
							$body = 'You have a meeting scheduled for '.$new_event_date;
							break;
						case MSG_REJECTED:
						case MSG_TENTATIVE:
						case MSG_ACCEPTED:
							$action_date = $old_event_date;
							$body = 'On '.$phpgw->common->show_date(time() - $new_tz_offset).' '.$phpgw->common->grab_owner_name($phpgw_info['user']['account_id']).' '.$action.' your meeting request for '.$old_event_date;
							break;
					}
					$subject = 'Calendar Event ('.$action.') #'.$event_id.': '.$action_date.' (L)';
					$returncode = $send->msg('email',$to,$subject,$body,$msgtype,'','','',$sender);
				}
			}
			unset($send);
		
			if((is_int($this->user) && $this->user != $temp_user['account_id']) ||
				(is_string($this->user) && $this->user != $temp_user['account_lid']))
			{
				$phpgw_info['user'] = $temp_user;
			}

			$phpgw_info['user']['preferences']['common']['tz_offset'] = $temp_tz_offset;
			$phpgw_info['user']['preferences']['common']['timeformat'] = $temp_timeformat;
			$phpgw_info['user']['preferences']['common']['dateformat'] = $temp_dateformat;
		}

		function prepare_recipients(&$new_event,$old_event)
		{
			// Find modified and deleted users.....
			while(list($old_userid,$old_status) = each($old_event->participants))
			{
				if(isset($new_event->participants[$old_userid]))
				{
//					echo "Modifying event for user ".$old_userid."<br>\n";
					$this->modified[intval($old_userid)] = $new_status;
				}
				else
				{
//					echo "Deleting user ".$old_userid." from the event<br>\n";
					$this->deleted[intval($old_userid)] = $old_status;
				}
			}
			// Find new users.....
			while(list($new_userid,$new_status) = each($new_event->participants))
			{
				if(!isset($old_event->participants[$new_userid]))
				{
//					echo "Adding event for user ".$new_userid."<br>\n";
					$this->added[$new_userid] = 'U';
					$new_event->participants[$new_userid] = 'U';
				}
			}
		
	      if(count($this->added) > 0 || count($this->modified) > 0 || count($this->deleted) > 0)
   	   {
				if(count($this->added) > 0)
				{
					$this->send_update(MSG_ADDED,$this->added,'',$new_event);
				}
				if(count($this->modified) > 0)
				{
					$this->send_update(MSG_MODIFIED,$this->modified,$old_event,$new_event);
				}
				if(count($this->deleted) > 0)
				{
					$this->send_update(MSG_DELETED,$this->deleted,$old_event);
				}
			}
		}



		function _debug_array($data)
		{
			echo '<br>UI:';
			_debug_array($data);
		}
	}
?>
