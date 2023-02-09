<?php

/***** CODE SAMPLE: A PHP/HTML page for geosearching database objects *****/


if(!$theUser->inRole(ROLE_ADMIN | ROLE_EMPLOYEE | ROLE_CLIENT))
    exit;

if($theUser->inRole(ROLE_CLIENT) && !$theUser->client_manager())
    exit;

$workorder = 0;
if($workorder_id = postval('workorder_id')) {
    if(($workorder = Workorder::getById($workorder_id)) && $workorder->found && ($site = $workorder->site()) && $site->found && ($address = $site->address()) && $address->found && $address->zip)
        $_REQUEST['zip'] = $address->zip;
    else throw new Exception("Couldn't find work order #$workorder_id site ZIP.");
}

$zip = preg_replace("/\s+/", "", isset($_REQUEST['zip']) ? $_REQUEST['zip'] : '');
add_script('var ZIP = ' . json_encode($zip) . ';', true);
$lat = (float) preg_replace("/\s+/", "", isset($_REQUEST['lat']) ? $_REQUEST['lat'] : '');
$long = (float) preg_replace("/\s+/", "", isset($_REQUEST['long']) ? $_REQUEST['long'] : '');
add_script('var LAT = ' . json_encode($lat) . ';', true);
add_script('var LONG = ' . json_encode($long) . ';', true);
if(isset($_REQUEST['menus']))
    add_script('var CONTEXT_MENU = ' . json_encode($_REQUEST['menus']) . ';', true);
if ($zip) {
    if(($result = $db_link->select("SELECT Z.latitude AS lat, Z.longitude AS lng FROM zipcode Z WHERE Z.code LIKE ?", [$zip])) || ($result = $db_link->select("SELECT Z.latitude AS lat, Z.longitude AS lng FROM zipcode_canada Z WHERE Z.code LIKE ?", [$zip])))
        $location = $result[0];
    else $errors[] = "ZIP / Postal code $zip not found";
}

$location = [ 'lat' => '38', 'lng' => '-95.7' ];  // display is centered continental US

$all_skills = Tag::select()->where('enabled = 1 AND status <> ?', [ Tag::Proposed ])->sort('name')->getAll();
$skills = [ ];
foreach($all_skills as $skill)
    $skills[$skill->id] = [ 'name' => $skill->name, 'internal' => $skill->status == Tag::Internal ];

$assigned = 0;
if($workorder && $workorder->installer_id) {
    $installer = Installer::getById($workorder->installer_id);
    $tech = 0;
    if($workorder->planned_tech_id)
        $tech = SiteUser::getById($workorder->planned_tech_id);
    else if($workorder->planned_tech_name)
        $tech = SiteUser::select()->where("enabled = 1 AND roles & " . ROLE_INSTALLER . " AND CONCAT(fname, ' ', lname) LIKE ?", $workorder->planned_tech_name)->getOne();
    $assigned = [ ];
    if($tech) {
        $assigned['user_id'] = $tech->user_id;
        $assigned['name'] = $tech->fname . ' ' . $tech->lname;
    }
    $assigned['installer_id'] = $installer->installer_id;
    $assigned['installer'] = $installer->company;
}
add_script('var ASSIGNED = ' . json_encode($assigned) . ';', true);

add_script('var Location = ' . json_encode($location) . ';', true);
add_script('var WORKORDER_ID = ' . json_encode($workorder_id) . ';', true);

add_plugin('/plugins/calendar-date-picker');
add_plugin('/plugins/escreen');
if(!isset($_GET['map']) || !empty($_GET['map']))  // to test the map API 'breaking', append &map=0 to url
    add_js('https://js.??.com/v3/3.1/mapsjs-core.js');
add_js('https://js.??.com/v3/3.1/mapsjs-service.js');
add_js('https://js.??.com/v3/3.1/mapsjs-mapevents.js');
add_js('https://js.??.com/v3/3.1/mapsjs-ui.js');

add_js('/admin/module/installers/js/search.js');
add_js('/admin/module/installers/js/search_map.js');
add_css('/admin/module/installers/css/search.css');

$apple_device = stripos($_SERVER['HTTP_USER_AGENT'],"ipod") || stripos($_SERVER['HTTP_USER_AGENT'],"iphone") || stripos($_SERVER['HTTP_USER_AGENT'],"ipad") || stripos($_SERVER['HTTP_USER_AGENT'],"mac");

?>
<link rel="stylesheet" type="text/css" href="https://js.api.here.com/v3/3.1/mapsjs-ui.css"/>

<form action="/admin/" id="installers-search-export" method="post">
    <input type="hidden" name="installers" id="installers-export" value="">
    <input type="hidden" name="filters" id="filters-export" value="">
    <input type="hidden" name="mod" value="installers">
    <input type="hidden" name="act" value="export-installer-search">
</form>
<div id="mainbar" class="stretch primary col flex-v eF">
    <header>
        <div class="header-left pull-left">
            <div class="xmarkers-container xfilter" style="display: inline-block" xdata-filter="markers"><span class="xmarkers-display">Techs</span><input type="hidden" name="markers" value="address" /></div>
            <span class="installer-container">from <div class="installer-display filter" data-filter="installer"><span class="installer"></span> installer</div><input type="hidden" name="installer" value="" /></span>
            <span class="skills-container">having <div class="skills-display filter" data-filter="skills"><span class="skills-display"></span> skill<span class="skill-label-plural">s</span></div><input type="hidden" name="skills" value="" /></span>
            <span class="workdate-container">working <div class="workdate-display filter" data-filter="workdate"><span class="total-jobs"></span> other job<span class="jobs-label-plural">s</span> on <span class="workdate-date"></span></div><input type="hidden" name="workdate" value="" /></span>
            <span class="distance-container">within <div class="distance-display filter" data-filter="distance">100 miles</div><input type="hidden" name="distance" value="100" /></span>
            <span class="coords-container">of <span class="latitude-big"></span>.<span class="latitude-small"></span><span class="longitude-big"></span>.<span class="longitude-small"></span></span>
            <span class="workorder-container"><span class="workorder-of">of</span><span class="workorder-for">for</span> <a class="workorder-link" href="#" target="_blank">work order #<span class="workorder-id"></span></a><?php if($workorder) { if($workorder->install_start && $workorder->install_start != '0000-00-00 00:00:00') print(' <span class="work-order-install-start">on ' . date('D, M j \a\t g:ia', strtotime($workorder->install_start)) . '</span>'); else print(' <span class="work-order-unscheduled">(unscheduled)</span>'); } ?></span>
            <span class="zip-container"><span class="zip-of">of</span><span class="zip-in">&hellip; or search in</span> ZIP Code <span class="zip-code"><input name="zip" type="text"></input></span></span>
            <button class="zip-go">
                <div class="not-going">GO</div>
                <div class="going"><div class="spinner"></div></div>
            </button>
            <span class="click-instruction">or right-click on map</span>
        </div>
        <a href="<?php if($workorder_id) print("/admin/?mod=workorders&id={$workorder_id}&act=assign"); else print("/admin/?mod=installers&act=locationsearch" . (!empty($zip) ? "&zipCode=$zip" : "")); ?>" class="legacy-page"><i class="fa fa-map-marker pull-right" aria-hidden="true" title="Go to old version of page"></i></a>
        <a href="#" class="tech-export"><i class="icon-file-excel tech-export pull-right" id="old_version" title="Export tech list"></i></a>
        <div class="filter-container">
            <i class="fa fa-filter" id="filter_icon">
                <div id="filter_menu">
<!--                    <div class="menu-item" data-filter="markers">map markers</div> -->
                    <div class="menu-item" data-filter="installer">installer</div>
                    <div class="menu-item" data-filter="distance">distance</div>
                    <div class="menu-item" data-filter="skills">skills</div>
                    <div class="menu-item" data-filter="workdate">other jobs on date</div>
                </div>
            </i>
        </div>
<!--        <i class="icon-cog2 ui-table-settings pull-right" title="Table settings" id="uitable_settings"></i> -->
        <div class="clear"></div>
    </header>
    <article class="stretch flex-v">
        <div class="stretch map-display" style="position:relative;">
            <div class="map-overlay">
                <div class="spinner-container">
                    <div class="spinner"></div>
                    <div class="map-overlay-label"></div>
                </div>
            </div>
            <div id="map"></div>
        </div>
        <div class="icon-tooltip-container">
            <div class="icon-arrow pull-left"></div>
            <div class="icon-tooltip pull-left">
                <div class="tech-name"></div>
                <div class="installer-name"></div>
            </div>
            <div class="clear"></div>
        </div>
        <div class="tech-legend-container autoleft">
            <div class="tech-legend-list">
                <div class="legend-container address-legend">
                    <div class="legend-icon"><img src="/images/map/address-legend.png"></div>
                    <div class="legend-label">tech address</div>
                </div>
                <div class="legend-container expired-legend">
                    <div class="legend-icon"><img src="/images/map/address-legend.png" class="marker expired"></div>
                    <div class="legend-label">expired insurance</div>
                </div>
                <div class="legend-container assigned-legend">
                    <div class="legend-icon"><img src="/images/map/assigned-legend.png"></div>
                    <div class="legend-label">assigned</div>
                </div>
                <div class="legend-container pool-legend">
                    <div class="legend-icon"><img src="/images/map/pool-legend.png"></div>
                    <div class="legend-label">in pool</div>
                </div>
                <div class="legend-container selected-legend">
                    <div class="legend-icon"><img src="/images/map/selected-legend.png"></div>
                    <div class="legend-label">selected</div>
                </div>
                <div class="legend-container address-associate-legend">
                    <div class="legend-icon"><img src="/images/map/address-associate-legend.png"></div>
                    <div class="legend-label">associate</div>
                </div>
            </div>
        </div>
        <div class="tech-pool-container autoleft autotop" data-top-selector="div.tech-assigned-container">
            <div class="tech-pool-label-container"><div class="tech-pool-label"><span class="tech-pool-label-pre"></span> tech<span class="tech-pool-label-plural">s</span> <span class="tech-pool-label-post">in pool</span></div></div>
            <div class="tech-pool-details">
                <div class="tech-pool-note">
                    <div class="pool-note-label pull-left">pool note:</div>
                    <textarea class="pool-note pull-left"></textarea>
                    <div class="clear"></div>
                </div>
                <div class="sort-label pull-left">sort:</div>
                <div class="pull-left">
                    <select name="sort">
                        <option value="added">order added</option>
                        <option value="installer">installer name</option>
                        <option value="tech">tech name</option>
                        <option value="response">response</option>
                    </select>
                    <button class="reverse-sort" title="reverse sort"><i class="fa fa-arrow-down" aria-hidden="true"></i><i class="fa fa-arrow-up" aria-hidden="true"></i></button>
                </div>
                <div class="accept-bids-container pull-right">
                    <label class="checkbox-container input-label accept-bids"><div class="accept-bids-label">accept bids</div>
                    <input type="checkbox" name="allow_bids">
                    <span class="checkmark"></span>
                    </label>
                </div>
                <div class="pull-right"><input type="text" name="deadline" class="datepicker"></div>
                <div class="input-label deadline pull-right">deadline: </div>
                <div class="clear"></div>
            </div>
            <div class="tech-pool-list">
            </div>
            <div class="tech-pool-installer-template hidden hoverable">
                <div class="tech-info">
                    <div class="tech-seen pull-right">
                        <div class="seen-type status-new" title="Tech has not viewed job"><i class="fa fa-clock-o" aria-hidden="true"></i></div>
                        <div class="seen-type status-seen" title="Tech viewed job at [TIME] but has not responded"><i class="fa fa-eye" aria-hidden="true"></i></div>
                        <div class="seen-type status-refused" title="Tech refused the job"><i class="fa fa-times" aria-hidden="true"></i></div>
                        <div class="seen-type status-accepted" title="Tech accepted the job"><i class="fa fa-check" aria-hidden="true"></i></div>
                    </div>
                    <div class="tech-name"></div>
                    <div class="installer-name"></div>
                    <div class="clear"></div>
                </div>
            </div>
            <div class="tech-pool-footer">
                <button class="tech inactive tech-pool-clear pull-left">clear pool</button>
                <div class="tech-pool-instructions"><b><?= $apple_device ? 'cmd' : 'ctrl' ?> + click</b> to remove</div>
                <button class="tech tech-pool-assign autoleft">assign tech to work order</button>
                <button class="tech inactive tech-pool-save pull-right">save pool</button>
                <div class="clear"></div>
            </div>
        </div>
        <div class="tech-assigned-container autoleft">
            <div class="tech-assigned-label-container"><div class="tech-assigned-label">assigned tech</div></div>
            <div class="tech-assigned-display hoverable">
                    <div class="tech-name"></div>
                    <div class="installer-name"></div>
                    <div class="clear"></div>
            </div>
            <div class="tech-assigned-footer">
                <button class="tech unassign pull-right">unassign</button>
                <div class="clear"></div>
            </div>
        </div>
        <div class="tech-list-container">
            <div class="tech-list-label-container"><div class="tech-list-label"><span class="tech-list-label-pre"></span> tech<span class="tech-list-label-plural">s</span><span class="tech-list-label-post"></span></div></div>
            <div class="tech-list-searching">
                <div class="spinner"></div>
                <div class="spinner-label">searching for techs</div>
            </div>
            <div class="tech-list-empty">
                no techs found
            </div>
            <div class="tech-list-filters">
                <div class="sort-label pull-left">sort:</div>
                <div class="pull-left">
                    <select name="sort">
                        <option value="distance">distance</option>
                        <option value="installer">installer name</option>
                        <option value="tech">tech name</option>
                        <option value="rating" data-sortdir="descend">rating</option>
                        <option value="jobs" data-sortdir="descend">jobs for client</option>
                        <option value="created" data-sortdir="descend">sign-up date</option>
                    </select>
                    <button class="reverse-sort" title="reverse sort"><i class="fa fa-arrow-down" aria-hidden="true"></i><i class="fa fa-arrow-up" aria-hidden="true"></i></button>
                </div>
                <div class="show-expired-container pull-left">
                    <label class="checkbox-container input-label show-expired"><div class="show-expired-label">show expired</div>
                    <input type="checkbox" name="show_expired">
                    <span class="checkmark"></span>
                    </label>
                </div>
                <div class="pull-right"><input type="text" name="quick_filter"></div>
                <div class="filter-label pull-right">search: </div>
                <div class="clear"></div>
            </div>
            <div class="tech-list-none-shown">
                no techs shown
            </div>
            <div class="tech-list">
                <div class="tech-template hidden hoverable pool-sensitive assign-sensitive">
                    <div class="tech-name pull-left"><span class="tech-name"></span></div>
                    <div class="distance pull-right"><span class="distance-label">miles</span><span class="distance"></span></div>
                    <div class="clear"></div>
                    <div class="installer-name pull-left"><span class="installer-name"></span></div>
                    <div class="rating pull-right"><span class="rating-label">rating</span><span class="rating"></span></div>
                    <div class="clear"></div>
                    <div class="tech-status pull-left"><span class="in-pool">in pool</span><span class="assigned">assigned</span></div>
                    <div class="jobs pull-left"><span class="jobs"></span><span class="jobs-label"> job<span class="jobs-label-plural">s</span> for client</span></div>
                    <div class="created pull-right"><span class="created-label">joined</span><span class="created"></span></div>
                    <div class="clear"></div>
                </div>
            </div>
            <div class="tech-list-legend legend">
                <div class="tech-list-instructions"><b><?= $apple_device ? 'cmd' : 'ctrl' ?> + click</b> to add to pool</div>
                <div class="tech-legend-label"></div>
                <div class="tech-legend-body">
                    <div class="legend-insured">insured</div>
                    <div class="legend-express">ExpressTech w/o insurance</div>
                    <div class="legend-expired">expired insurance</div>
                </div>
            </div>
        </div>
        <div class="tech-detail-container">
            <div class="tech-detail-label-container"><div class="tech-detail-label">tech details</div></div>
            <div class="tech-detail sticky">
                <div class="tech-section section core-display">
                    <div class="section-head">tech</div>
                    <div class="section-body">
                        <div class="tech-photo-container pull-right"></div>
                        <a class="tech-name-link" href="#" target="_blank"><div class="tech-name"></div></a>
                        <div class="tech-address-container no-flow-partial"><div class="tech-icon" icon="home"></div><div class="tech-address"></div></div>
                        <div class="tech-phone-container no-flow-partial"><div class="tech-icon" icon="phone2"></div><div class="tech-phone"></div></div>
                        <div class="tech-email-container no-flow-partial"><a class="tech-email-link" href="#"><div class="tech-icon" icon="envelope-alt"></div><div class="tech-email"></div></a></div>
                        <div class="clear"></div>
                    </div>
                </div>
                <div class="actions-container">
                    <div class="force-assign-container action pull-left">
                        <button class="tech force-assign assign-tech">force assign</button>
                    </div>
                    <div class="add-to-pool-container action pull-right">
                        <button class="tech add-to-pool">add to pool</button>
                    </div>
                    <div class="remove-from-pool-container action pull-right">
                        <button class="tech remove-from-pool">remove from pool</button>
                    </div>
                    <div class="clear"></div>
                </div>
            </div>
            <div class="tech-detail primary">
                <div class="tech-detail-body">
                    <div class="installer-section section core-display">
                        <div class="section-head">installer</div>
                        <div class="section-body">
                            <a class="installer-name-link" href="#" target="_blank"><div class="installer-name"></div></a>
                            <div class="installer-phone-container no-flow-partial"><div class="tech-icon" icon="phone2"></div><div class="installer-phone"></div></div>
                            <div class="installer-email-container no-flow-partial"><a class="installer-email-link" href="#"><div class="tech-icon" icon="envelope-alt"></div><div class="installer-email"></div></a></div>
                            <div class="insurance-status expired">EXPIRED INSURANCE</div>
                            <div class="insurance-status express">EXPRESSTECH without INSURANCE</div>
                            <div class="insurance-status expiring">insurance expires on <span class="expire-date"></span> <span class="expire-days"></span></div>
                        </div>
                    </div>
                    <div class="skills-section section" data-section="skills">
                        <div class="section-head">skills <span class="section-summary"></span><div class="section-collapser pull-right"><i class="fa fa-chevron-up" aria-hidden="true"></i><i class="fa fa-chevron-down" aria-hidden="true"></i></div><div class="clear"></div></div>
                        <div class="section-body"></div>
                    </div>
                    <div class="pool-notes-section section" data-section="pool-notes">
                        <div class="section-head">pool note</div>
                        <div class="section-body"><textarea class="tech-pool-note" id="pool_tech_note"></textarea></div>
                    </div>
                    <div class="tech-notes-section section" data-section="tech-notes">
                        <div class="section-head">tech notes <span class="section-summary"></span><div class="section-collapser pull-right"><i class="fa fa-chevron-up" aria-hidden="true"></i><i class="fa fa-chevron-down" aria-hidden="true"></i></div><div class="clear"></div></div>
                        <div class="section-body"></div>
                    </div>
                    <div class="installer-notes-section section" data-section="installer-notes">
                        <div class="section-head">company notes <span class="section-summary"></span><div class="section-collapser pull-right"><i class="fa fa-chevron-up" aria-hidden="true"></i><i class="fa fa-chevron-down" aria-hidden="true"></i></div><div class="clear"></div></div>
                        <div class="section-body"></div>
                    </div>
                    <div class="ratings-section section" data-section="ratings">
                        <div class="section-head">rating <span class="section-summary"></span><div class="section-collapser pull-right"><i class="fa fa-chevron-up" aria-hidden="true"></i><i class="fa fa-chevron-down" aria-hidden="true"></i></div><div class="clear"></div></div>
                        <div class="section-body">
                            <div class="rating-loading">
                                <div class="tech-ratings-loading">
                                    <div class="spinner"></div>
                                    <div class="spinner-label">loading tech ratings</div>
                                </div>
                            </div>
                            <div class="rating-display">
                                <div class="overall-rating-container">
                                    <div class="rlabel overall-rating-header">Calculated Rating:</div>
                                    <div class="rvalue overall-rating">--</div>
                                    <div class="clear"></div>
                                </div>

                                <div class="rlabel">Completed in Timely Manner avg:</div>
                                <div class="rvalue timeliness">--</div>
                                <div class="clear"></div>

                                <div class="rlabel">Quality of Service avg:</div>
                                <div class="rvalue quality">--</div>
                                <div class="clear"></div>

                                <div class="rlabel">Communications avg:</div>
                                <div class="rvalue communication">--</div>
                                <div class="clear"></div>

                                <div class="rlabel">Followed WO Instructions avg:</div>
                                <div class="rvalue follow-instructions">--</div>
                                <div class="clear"></div>
                            </div>
                            <div class="rating-display">
                                <div class="rlabel">% on time (within 10 min).  Tech:</div>
                                <div class="rvalue grace-tech">--</div>
                                <div class="rlabel">&nbsp;Company:</div>
                                <div class="rvalue grace-installer">--</div>
                                <div class="clear"></div>

                                <div class="rlabel">Avg time to turn in deliverables.  Tech:</div>
                                <div class="rvalue deliverables-tech">--</div>
                                <div class="rlabel">&nbsp;Company:</div>
                                <div class="rvalue deliverables-installer">--</div>
                                <div class="clear spacer"></div>

                                <div class="rlabel">Jobs completed for ?.  Tech:</div>
                                <div class="rvalue tl-jobs-tech">--</div>
                                <div class="rlabel">&nbsp;Company:</div>
                                <div class="rvalue tl-jobs-installer">--</div>
                                <div class="rlabel">Previous job for ?.  Tech:</div>
                                <div class="rvalue tl-previous-job-tech">--</div>
                                <div class="clear spacer"></div>

                                <div class="rlabel">WOs Cancelled:</div>
                                <div class="rvalue cancel-tech">--</div>
                                <div class="clear"></div>

                                <div class="rlabel">WOs Unassigned:</div>
                                <div class="rvalue unassign-tech">--</div>
                                <div class="clear"></div>

                                <div class="rlabel">Service Date Changes:</div>
                                <div class="rvalue reschedule-tech">--</div>
                                <div class="clear"></div>

                                <div class="rlabel">Return Trips:</div>
                                <div class="rvalue return-tech">--</div>
                                <div class="clear"></div>
                            </div>
                        </div>
                    </div>
                    <div class="tech-detail-loading-container">
                        <div class="tech-loading-info">
                            <div class="tech-name"></div>
                            <div class="installer-name"></div>
                        </div>
                        <div class="tech-detail-loading">
                            <div class="spinner"></div>
                            <div class="spinner-label">loading tech details</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </article>
</div>

<div class="unassign-installer-survey survey-container">
    <div class="survey">
        <div class="survey-header">UNASSIGN-INSTALLER SURVEY</div>
        <div style="padding: 10px 15px 5px 15px">
            <div style="margin-bottom: 5px">What caused this unassignment?</div>
            <div style="float: left; margin-left: 5px"><input type='radio' name='unassign_cause' id='unassign_cause_installer' value='installer'><label for='unassign_cause_installer'>Tech</div>
            <div style="float: left; margin-left: 15px"><input type='radio' name='unassign_cause' id='unassign_cause_client' value='client'><label for='unassign_cause_client'>Client / Site</div>
            <div style="float: left; margin-left: 15px"><input type='radio' name='unassign_cause' id='unassign_cause_?' value='?'><label for='unassign_cause_?'>??</label></div>
            <div style="float: left; margin-left: 15px"><input type='radio' name='unassign_cause' id='unassign_cause_none' value='none'><label for='unassign_cause_none'>No Fault</label></div>
            <div class="clear"></div>
            <div class="survey-note-container">
                <div style="margin: 10px 0 0 0">Note (required):</div>
                <textarea name="unassign_note" class="survey-note"></textarea>
            </div>
        </div>
        <button type="submit" id="cancel_unassign_installer">Cancel</button>
        <button type="submit" id="unassign_installer">Unassign</button>
        <div class="clear"></div>
    </div>
</div>

<div class="popup-container">
    <div id="filter_popup_distance" class="primary flex-v filter-popup">
        <header>Distance Filter</header>
        <article class="stretch eF clrfx font size-2 pad3">
            <input type="radio" name="filter_distance" value="50" id="fd50"/><label for="fd50">50 miles</label><br/>
            <input type="radio" name="filter_distance" value="100" id="fd100"/><label for="fd100">100 miles</label><br/>
            <input type="radio" name="filter_distance" value="200" id="fd200"/><label for="fd200">200 miles</label><br/>
            <input type="radio" name="filter_distance" value="custom" id="fdc"/><label for="fdc">Custom: </label><input type="text" id="fdcustom"/>
            <div class="??-anywhere">
                <a href="#" class="??-anywhere">search for ?? techs anywhere</a>
            </div>
        </article>
        <footer>
            <button id="filter_okay_distance">Okay</button>
        </footer>
    </div>
</div>

<div class="popup-container">
    <div id="filter_po`pup_markers" class="primary flex-v filter-popup">
        <header>Show Pins Filter</header>
        <article class="stretch eF clrfx font size-2 pad3">
            <div style="position:relative; padding:0 6px">
                <input type="checkbox" id="fmaddress" value="installers"/>
                <label for="fmaddress">Tech Address</label>
            </div>
<!--
            <div style="position:relative; padding:0 6px">
                <input type="checkbox" id="fmphone" value="techs"/>
                <label for="fmphone">Last-Known Mobile Location</label>
            </div>
-->
            <footer>
                <button id="filter_okay_markers">Okay</button>
            </footer>
        </article>
    </div>
</div>

<style>
</style>
<div class="popup-container">
    <div id="filter_popup_skills" class="primary flex-v filter-popup">
        <header>
            <div class="skills-header pull-left">
                Skills Filter
            </div>
            <div class="skills-scope pull-left">
                require
                <select name="skills_scope" class="skills-scope">
                    <option value="all">all</option>
                    <option value="any">any</option>
                </select>
                selected skills
            </div>
            <div class="skills-refine-container pull-right">
                <div class="pull-right"><input type="text" name="skills_quick_filter"></div>
                <div class="filter-label pull-right">search: </div>
                <div class="clear"></div>
            </div>
            <div style="clear: both"></div>
        </header>
        <article class="eF clrfx font size-2">
<?php
    foreach ($skills as $id => $skill) {
?>
                <div class="skill-item" title="<?php print($skill['name']); ?>" data-skill-id="<?php print($id); ?>">
                    <input type="checkbox" id="fs<?php echo $id; ?>" value="<?php echo $id; ?>"/>
                    <label for="fs<?php echo $id; ?>"<?php if($skill['internal']) print(' class="internal"'); ?>><?php echo $skill['name']; ?></label>
                </div>
<?php
    }
?>
        </article>
        <footer>
            <a id="filter_clear_skills" href="#">clear</a>
            <button id="filter_okay_skills">Okay</button>
        </footer>
    </div>
</div>

<div class="popup-container">
    <div id="filter_popup_workdate" class="primary flex-v filter-popup">
        <header>
            Work Date Filter
        </header>
        <article class="stretch eF clrfx font size-2 pad3">
            <input type="text" class="datepicker" id="workdate"></input>
            <label>Availability on this date:</label>
            <select id="workdate_limit"><?php print_options(['0'=>'No Other Installs', '1'=>'Max 1 Other Install', '2'=>'Max 2 Other Installs']); ?></select>
        </article>
        <footer>
            <a id="filter_clear_workdate" href="#">clear</a>
            <button id="filter_okay_workdate">Okay</button>
        </footer>
    </div>
</div>

<div class="popup-container">
    <div id="filter_popup_installer" class="primary flex-v filter-popup">
        <header>
            Installer Company Filter
        </header>
        <article class="stretch eF clrfx font size-2 pad3">
            <label>Installer company name:</label>
            <input type="text" id="installer"></input>
            <div class="??-anywhere">
                <a href="#" class="??-anywhere">search for ?? techs anywhere</a>
            </div>
        </article>
        <footer>
            <a id="filter_clear_installer" href="#">clear</a>
            <button id="filter_okay_installer">Okay</button>
        </footer>
    </div>
</div>
