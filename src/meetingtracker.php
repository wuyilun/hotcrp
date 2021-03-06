<?php
// meetingtracker.php -- HotCRP meeting tracker support
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MeetingTracker {
    static function lookup() {
        global $Conf, $Now;
        $tracker = $Conf->setting_json("tracker");
        if ($tracker && (!$tracker->trackerid || $tracker->update_at >= $Now - 150))
            return $tracker;
        else if ($tracker)
            return (object) ["trackerid" => false, "position_at" => $tracker->position_at + 0.1];
        else
            return (object) ["trackerid" => false, "position_at" => 0];
    }

    static private function next_position_at() {
        global $Conf;
        $tracker = $Conf->setting_json("tracker");
        return max(microtime(true), $tracker ? $tracker->position_at + 0.2 : 0);
    }

    static function clear() {
        global $Conf;
        if ($Conf->setting("tracker")) {
            $t = ["trackerid" => false, "position_at" => self::next_position_at()];
            $Conf->save_setting("tracker", 0, (object) $t);
            self::contact_tracker_comet();
        }
        return null;
    }

    static function update($list, $trackerid, $position) {
        global $Conf, $Me, $Now;
        assert($list && str_starts_with($list->listid, "p/"));
        ensure_session();
        if (preg_match('/\A[1-9][0-9]*\z/', $trackerid))
            $trackerid = (int) $trackerid;
        $tracker = (object) array("trackerid" => $trackerid,
                                  "listid" => $list->listid,
                                  "ids" => $list->ids,
                                  "url" => $list->url,
                                  "description" => $list->description,
                                  "start_at" => $Now,
                                  "position_at" => 0,
                                  "update_at" => $Now,
                                  "owner" => $Me->contactId,
                                  "sessionid" => session_id(),
                                  "position" => $position);
        $old_tracker = self::lookup();
        if ($old_tracker->trackerid == $tracker->trackerid) {
            $tracker->start_at = $old_tracker->start_at;
            if ($old_tracker->listid == $tracker->listid
                && $old_tracker->position == $tracker->position)
                $tracker->position_at = $old_tracker->position_at;
        }
        if (!$tracker->position_at)
            $tracker->position_at = self::next_position_at();
        $Conf->save_setting("tracker", 1, $tracker);
        self::contact_tracker_comet();
        return $tracker;
    }

    static function contact_tracker_comet($pids = null) {
        global $Conf, $Now;

        $comet_dir = $Conf->opt->trackerCometUpdateDirectory;
        $comet_url = $Conf->opt->trackerCometSite;
        if (!$comet_dir && !$comet_url)
            return;

        // calculate status
        $conference = Navigation::site_absolute();
        $tracker = self::lookup();

        // first drop notification json in trackerCometUpdateDirectory
        if ($comet_dir) {
            $j = array("ok" => true, "conference" => $conference,
                       "tracker_status" => self::tracker_status($tracker),
                       "tracker_status_at" => $tracker->position_at);
            if ($pids)
                $j["pulse"] = true;
            if (!str_ends_with($comet_dir, "/"))
                $comet_dir .= "/";
            $suffix = "";
            $count = 0;
            while (($f = @fopen($comet_dir . $Now . $suffix, "x")) === false
                   && $count < 20) {
                $suffix = "x" . mt_rand(0, 65535);
                ++$count;
            }
            if ($f !== false) {
                fwrite($f, json_encode($j));
                fclose($f);
                return;
            } else
                trigger_error("$comet_dir not writable", E_USER_WARNING);
        }

        // second contact trackerCometSite
        if (!$comet_url)
            return;

        if (!preg_match(',\Ahttps?:,', $comet_url)) {
            preg_match(',\A(.*:)(//[^/]*),', $conference, $m);
            if ($comet_url[0] !== "/")
                $comet_url = "/" . $comet_url;
            if (preg_match(',\A//,', $comet_url))
                $comet_url = $m[1] . $comet_url;
            else
                $comet_url = $m[1] . $m[2] . $comet_url;
        }
        if (!str_ends_with($comet_url, "/"))
            $comet_url .= "/";

        $context = stream_context_create(array("http" =>
                                               array("method" => "GET",
                                                     "ignore_errors" => true,
                                                     "content" => "",
                                                     "timeout" => 1.0)));
        $comet_url .= "update?conference=" . urlencode($conference)
            . "&tracker_status=" . urlencode(self::tracker_status($tracker))
            . "&tracker_status_at=" . $tracker->position_at;
        if ($pids)
            $comet_url .= "&pulse=1";
        $stream = @fopen($comet_url, "r", false, $context);
        if (!$stream) {
            $e = error_get_last();
            error_log($comet_url . ": " . $e["message"]);
            return false;
        }
        if (!($data = stream_get_contents($stream))
            || !($data = json_decode($data)))
            error_log($comet_url . ": read failure");
        fclose($stream);
    }

    static private function status_papers($status, $tracker, $acct) {
        global $Conf;

        $pids = array_slice($tracker->ids, $tracker->position, 3);

        $pc_conflicts = $acct->privChair || $acct->tracker_kiosk_state;
        $col = $j = "";
        if ($pc_conflicts) {
            $col = ", allconfs.conflictIds";
            $j = "left join (select paperId, group_concat(contactId) conflictIds from PaperConflict where paperId in (" . join(",", $pids) . ") group by paperId) allconfs on (allconfs.paperId=p.paperId)\n\t\t";
            $pcm = pcMembers();
        }

        $result = $Conf->qe_raw("select p.paperId, p.title, p.paperFormat, p.leadContactId, p.managerContactId, r.reviewType, conf.conflictType{$col}
            from Paper p
            left join PaperReview r on (r.paperId=p.paperId and " . ($acct->contactId ? "r.contactId=$acct->contactId" : "false") . ")
            left join PaperConflict conf on (conf.paperId=p.paperId and " . ($acct->contactId ? "conf.contactId=$acct->contactId" : "false") . ")
            ${j}where p.paperId in (" . join(",", $pids) . ")");

        $papers = array();
        while (($row = PaperInfo::fetch($result, $acct))) {
            $papers[$row->paperId] = $p = (object) array();
            if (($acct->privChair || !$row->conflictType
                 || !get($status, "hide_conflicts"))
                && $acct->tracker_kiosk_state != 1) {
                $p->pid = (int) $row->paperId;
                $p->title = $row->title;
                if (($format = $row->title_format()))
                    $p->format = $format;
            }
            if ($acct->contactId > 0
                && $row->managerContactId == $acct->contactId)
                $p->is_manager = true;
            if ($row->reviewType)
                $p->is_reviewer = true;
            if ($row->conflictType)
                $p->is_conflict = true;
            if ($acct->contactId > 0
                && $row->leadContactId == $acct->contactId)
                $p->is_lead = true;
            if ($pc_conflicts) {
                $p->pc_conflicts = array();
                foreach (explode(",", (string) $row->conflictIds) as $cid)
                    if (($pc = get($pcm, $cid)))
                        $p->pc_conflicts[$pc->sort_position] = (object) array("email" => $pc->email, "name" => Text::name_text($pc));
                ksort($p->pc_conflicts);
                $p->pc_conflicts = array_values($p->pc_conflicts);
            }
        }

        Dbl::free($result);
        $status->papers = array();
        foreach ($pids as $pid)
            $status->papers[] = $papers[$pid];
    }

    static function info_for($acct) {
        global $Conf, $Now;
        $tracker = self::lookup();
        if (!$tracker->trackerid || !$acct->can_view_tracker())
            return false;
        if (($status = $Conf->session("tracker"))
            && $status->trackerid == $tracker->trackerid
            && $status->position == $tracker->position
            && $status->calculated_at >= $Now - 30
            && !$acct->is_actas_user())
            return $status;
        $status = (object) array("trackerid" => $tracker->trackerid,
                                 "listid" => $tracker->listid,
                                 "position" => $tracker->position,
                                 "start_at" => $tracker->start_at,
                                 "position_at" => $tracker->position_at,
                                 "url" => $tracker->url,
                                 "calculated_at" => $Now);
        if ($Conf->opt->trackerHideConflicts)
            $status->hide_conflicts = true;
        if ($status->position !== false)
            self::status_papers($status, $tracker, $acct);
        if (!$acct->is_actas_user())
            $Conf->save_session("tracker", $status);
        return $status;
    }

    static function tracker_status($tracker) {
        if ($tracker->trackerid)
            return $tracker->trackerid . "@" . $tracker->position_at;
        else
            return "off";
    }

    static function trackerstatus_api($user = null, $qreq = null, $prow = null) {
        $tracker = self::lookup();
        json_exit(["ok" => true,
                   "tracker_status" => self::tracker_status($tracker),
                   "tracker_status_at" => $tracker->position_at]);
    }

    static function track_api($qreq, $user) {
        if (!$user->privChair || !check_post())
            json_exit(array("ok" => false));
        // argument: IDENTIFIER LISTNUM [POSITION] -OR- stop
        if ($qreq->track === "stop") {
            self::clear();
            return;
        }
        // check tracker_start_at to ignore concurrent updates
        if (($start_at = $qreq->tracker_start_at)
            && ($tracker = self::lookup())) {
            $time = $tracker->position_at;
            if (isset($tracker->start_at))
                $time = $tracker->start_at;
            if ($time > $start_at)
                return;
        }
        // actually track
        $args = preg_split('/\s+/', $qreq->track);
        if (count($args) >= 2
            && ($xlist = SessionList::lookup($args[1]))
            && str_starts_with($xlist->listid, "p/")) {
            $position = null;
            if (count($args) >= 3 && ctype_digit($args[2]))
                $position = array_search((int) $args[2], $xlist->ids);
            self::update($xlist, $args[0], $position);
        }
    }
}
