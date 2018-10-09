<?php

declare(strict_types=1);

namespace Hakoniwa\Model;

require_once __DIR__."/../../config.php";

trait FileIO
{
    final protected function read_gameboard_file(): void
    {
    }

    final protected function read_players_data(): void
    {
    }

    final protected function read_alliances_file(): void
    {
    }

    final protected function read_present_file(): void
    {
    }

    final protected function pick_player_data(): void
    {
    }

    final protected function pick_alliance_data(): void
    {
    }

    final protected function write_gameboard_file(): void
    {
    }

    final protected function write_players_file(): void
    {
    }

    final protected function write_alliances_file(): void
    {
    }

    final private function mkfile(string $filepath): bool
    {
        $info = pathinfo($this->parse_path($filepath));

        $filepath = $info["dirname"].DIRECTORY_SEPARATOR.$info["basename"];
        $file_stat = $this->is_usable_path($filepath, true)["file"];
        $dir_stat = $this->is_usable_path($info["dirname"], true);

        if ($file_stat || $dir_stat["file"]) {
            return false;
        }

        if (!$dir_stat["dir"]) {
            if (!mkdir($info["dirname"], 0775, true)) {
                return false;
            }
        }

        return file_put_contents($filepath, "", LOCK_EX) !== false;
    }



    final private function parse_path(string $path): string
    {
        $segments = preg_split("/(\/|\\\\)/", $path);
        $parsed_path = [];
        $s = DIRECTORY_SEPARATOR;
        $cwd = getcwd();

        $has_driveletter = 1 === preg_match("/^[a-zA-Z]:\.?$/", $segments[0]);
        $is_absolute_path = $segments[0] === "" || $has_driveletter;

        if (!WINDOWS && $has_driveletter) {
            throw new \InvalidArgumentException("Failed parse: `{$path}`");
        }

        if ($segments[0] === "~") {
            $home = WINDOWS ? getenv("USERPROFILE") : (getenv("PATH") ?? posix_getpwuid(posix_geteuid())["dir"]);

            if (false !== $home) {
                return $this->parse_path($home.mb_substr($path, 1));
            } else {
                throw new \RuntimeException("Failed parse: `{$path}`");
            }
        }

        if (!$is_absolute_path) {
            return $this->parse_path($cwd.$s.$path);
        }

        $depth = 0;
        foreach ($segments as $seg) {
            if ($depth !== 0 && 1 === preg_match("/^[a-zA-Z]:\.?$/", $seg)) {
                throw new \RuntimeException("Failed parse: `{$path}`");
            }
            switch ($seg) {
                case ".":
                case "":
                    // noop
                break;
                case "..":
                    $depth -= ($depth === 0) ? 0 : 1;
                    $depth = (WINDOWS && $has_driveletter) ? max($depth, 1) : $depth;

                break;
                default:
                    $parsed_path[$depth] = $seg;
                    $depth += 1;

                break;
            }
        }

        if ($depth !== count($parsed_path)) {
            array_splice($parsed_path, $depth);
        }


        if (WINDOWS) {
            if ($has_driveletter) {
                $path_prefix = "";
                $parsed_path[0] = mb_strtoupper($parsed_path[0]);
            } else {
                $path_prefix = mb_substr($cwd, 0, mb_strpos($cwd, ":") + 1).$s;
            }
        } else {
            $path_prefix = $s;
        }

        return $path_prefix.implode($s, $parsed_path);
    }



    final private function is_usable_path(string $path, bool $verbose = false): array
    {
        $stat = [
            "file" => false,
            "dir"  => false
        ];
        $stat_verbose = [
            "r" => false,
            "w" => false,
            "x" => false
        ];

        if (!file_exists($path)) {
            return !$verbose ? $stat : array_merge($stat, $stat_verbose);
        }

        $stat["file"] = is_file($path);
        $stat["dir"] = is_dir($path);
        $stat_verbose["r"] = is_readable($path);
        $stat_verbose["w"] = is_writable($path);
        $stat_verbose["x"] = is_executable($path);

        if (!$verbose) {
            $stat["file"] = $stat["file"] && $stat_verbose["r"] && $stat_verbose["w"];
            $stat["dir"] = $stat["dir"] && $stat_verbose["r"] && $stat_verbose["w"];

            return $stat;
        } else {
            return array_merge($stat, $stat_verbose);
        }
    }



    final protected function rimraf(string $path): bool
    {
        if (!is_dir($this->parse_path($path))) {
            return false;
        }

        $ls = array_diff(scandir($path), [".", ".."]);
        foreach ($ls as $file) {
            $p = $path.DIRECTORY_SEPARATOR.$file;
            is_dir($p) ? $this->rimraf($p) : unlink($p);
        }
        unset($p, $ls);

        return rmdir($path);
    }



    final protected function cp_a(string $from, string $to, bool $recursion = false): void
    {
        $from = $this->parse_path($from);
        $to = $this->parse_path($to);

        if(!$recursion) {
            if(!$this->is_usable_path($from)["dir"]) {
                throw new \ErrorException("No have permission to Read/Write: `{$from}`.");
            }
            if(!$this->is_usable_path($to)["dir"]) {
                throw new \ErrorException("No have permission to Read/Write: `{$to}`.");
            }
        }

        $ls = array_diff(scandir($from), [".", "..", ".git", "vendor"]);
        foreach ($ls as $file) {
            $f = $from.DIRECTORY_SEPARATOR.$file;
            $t = $to.DIRECTORY_SEPARATOR.$file;
            if(is_dir($f)) {
                mkdir($t);
                $this->cp_a($f, $t, true);
            } else {
            copy($f, $t);
            }
        }
        unset($f, $t, $ls);

        return;
    }



    final protected function mkdir_tmp()
    {
        $tmp_dir = $this->parse_path(sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->random_str());
        if(mkdir($tmp_dir, 0777, true)) {
            return $tmp_dir;
        }

        return false;
    }


    // [TODO] move to \Util
    final private function random_str($length = 8): string
    {
        static $seeds;

        if (!$seeds) {
            $seeds = array_flip(array_merge(range("a", "z"), range("A", "Z"), range("0", "9")));
        }
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= array_rand($seeds);
        }

        return $str;
    }
}
