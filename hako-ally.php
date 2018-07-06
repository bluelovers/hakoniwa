<?php
/**
 * 箱庭諸島 S.E - 同盟管理用ファイル -
 * @copyright 箱庭諸島 ver2.30
 * @since 箱庭諸島 S.E ver23_r09 by SERA
 * @author hiro <@hiro0218>
 */

require_once 'config.php';
require_once MODELPATH.'/hako-cgi.php';
require_once PRESENTER.'/hako-html.php';
require_once MODELPATH.'/Alliance.php';
require_once HELPERPATH.'Util_alliance.php';

use \Hakoniwa\Helper\Util_alliance as Util;

$init = new Init;

class MakeAlly
{
    //--------------------------------------------------
    // 解散
    //--------------------------------------------------
    public function delete_alliance($hako, $data)
    {
        global $init;

        $current_ID = $data['ISLANDID'];
        $currentAnumber = $data['ALLYNUMBER'];
        $currentNumber = $hako->idToNumber[$current_ID];
        $island = $hako->islands[$currentNumber];
        $n = $hako->idToAllyNumber[$current_ID];
        $adminMode = 0;

        // パスワードチェック
        $passCheck = isset($data['OLDPASS']) ? Util::checkPassword("", $data['OLDPASS']) : false;
        if ($passCheck) {
            $n = $currentAnumber;
            $current_ID = $hako->ally[$n]['id'];
            $adminMode = 1;
        } else {
            // passwordの判定
            if (!(Util::checkPassword($island['password'], $data['PASSWORD']))) {
                // 島 Password 間違い
                HakoError::wrongPassword();

                return;
            }
            if (!(Util::checkPassword($hako->ally[$n]['password'], $data['PASSWORD']))) {
                // 同盟 Password 間違い
                HakoError::wrongPassword();

                return;
            }
            // 念のためIDもチェック
            if ($hako->ally[$n]['id'] != $current_ID) {
                HakoError::wrongAlly();

                return;
            }
        }
        $allyMember = $hako->ally[$n]['memberId'];

        if ($adminMode && (($allyMember[0] != '') || ($n == ''))) {
            echo "削除できません。\n";

            return;
        }
        foreach ($allyMember as $id) {
            $island = $hako->islands[$hako->idToNumber[$id]];
            $newId = [];
            foreach ($island['allyId'] as $aId) {
                if ($aId != $current_ID) {
                    array_push($newId, $aId);
                }
            }
            $island['allyId'] = $newId;
        }
        $hako->ally[$n]['dead'] = 1;
        $hako->idToAllyNumber[$current_ID] = '';
        $hako->allyNumber--;

        // データ格納先へ
        $hako->islands[$currentNumber] = $island;

        // データ書き出し
        Util::calculates_share($hako);
        Util::allySort($hako);
        $hako->writeAllyFile();

        // トップへ
        $html = new HtmlAlly();
        $html->allyTop($hako, $data);
    }

    //--------------------------------------------------
    // 加盟・脱退
    //--------------------------------------------------
    public function joinAllyMain($hako, $data)
    {
        global $init;

        $current_ID = $data['ISLANDID'];
        $currentAnumber = $data['ALLYNUMBER'];
        $currentNumber = $hako->idToNumber[$current_ID];
        $island = $hako->islands[$currentNumber];

        // パスワードチェック
        if (!(Util::checkPassword($island['password'], $data['PASSWORD']))) {
            // password間違い
            HakoError::wrongPassword();

            return;
        }

        // 盟主チェック
        if ($hako->idToAllyNumber[$current_ID]) {
            HakoError::leaderAlready();

            return;
        }
        // 複数加盟チェック
        $ally = $hako->ally[$currentAnumber];
        if ($init->allyJoinOne && ($island['allyId'][0] != '') && ($island['allyId'][0] != $ally['id'])) {
            HakoError::otherAlready();

            return;
        }

        $allyMember = $ally['memberId'];
        $newAllyMember = [];
        $flag = 0;

        foreach ($allyMember as $id) {
            if (!($hako->idToNumber[$id] > -1)) {
            } elseif ($id == $current_ID) {
                $flag = 1;
            } else {
                array_push($newAllyMember, $id);
            }
        }

        if ($flag) {
            // 脱退
            $newAlly = [];
            foreach ($island['allyId'] as $id) {
                if ($id != $ally['id']) {
                    array_push($newAlly, $id);
                }
            }
            $island['allyId'] = $newAlly;
            $ally['score'] -= $island['pop'];
            $ally['number']--;
        } else {
            // 加盟
            array_push($newAllyMember, $current_ID);
            array_push($island['allyId'], $ally['id']);
            $ally['score'] += $island['pop'];
            $ally['number']++;
        }
        $island['money'] -= $init->comCost[$init->comAlly];
        $ally['memberId'] = $newAllyMember;

        // データ格納先へ
        $hako->islands[$currentNumber] = $island;
        $hako->ally[$currentAnumber] = $ally;

        // データ書き出し
        Util::calculates_share($hako);
        Util::allySort($hako);
        $hako->writeAllyFile();

        // トップへ
        $html = new HtmlAlly;
        $html->allyTop($hako, $data);
    }

    //--------------------------------------------------
    // 盟主コメントモード
    //--------------------------------------------------
    public function allyPactMain($hako, $data)
    {
        $ally = $hako->ally[$hako->idToAllyNumber[$data['ALLYID']]];

        if (Util::checkPassword($ally['password'], $data['Allypact'])) {
            $ally['comment'] = Util::htmlEscape($data['ALLYCOMMENT']);
            $ally['title'] = Util::htmlEscape($data['ALLYTITLE']);
            $ally['message'] = Util::htmlEscape($data['ALLYMESSAGE'], 1);

            $hako->ally[$hako->idToAllyNumber[$data['ALLYID']]] = $ally;
            // データ書き出し
            $hako->writeAllyFile();

            // 変更成功
            Success::allyPactOK($ally['name']);
        } else {
            // password間違い
            HakoError::wrongPassword();

            return;
        }
    }

    /**
     * 同盟関連データの再計算
     * @param  [type] &$hako [description]
     * @return bool          更新があった場合true
     */
    public function allyReComp(&$hako)
    {
        /**
         * 同盟の消失・加盟脱退・所属人口の更新など
         */
        $rt1 = $this->allyDelete($hako);
        $rt2 = $this->allyMemberDel($hako);
        $rt3 = $this->allyPopComp($hako);

        if ($rt1 || $rt2 || $rt3) {
            // データ書き出し
            Util::calculates_share($hako);
            Util::allySort($hako);
            $hako->writeAllyFile();

            // メッセージ出力
            Success::allyDataUp();

            return true;
        }

        return false;
    }

    /**
     * 同盟主登録のある島IDが存在しないとき、登録先の同盟を消す
     * @param  [type] &$hako [description]
     * @return bool          削除処理が走った場合true
     */
    public function allyDelete(&$hako)
    {
        $count = 0;

        for ($i=0; $i<$hako->allyNumber; $i++) {
            $id = $hako->ally[$i]['id'];

            if ($hako->idToNumber[$id] < 0) {
                // 配列から削除
                $hako->ally[$i]['dead'] = 1;
                $hako->idToAllyNumber[$id] = '';
                $count++;
            }
        }

        if ($count) {
            $hako->allyNumber -= $count;
            if ($hako->allyNumber < 0) {
                $hako->allyNumber = 0;
            }
            // データ格納先へ
            $hako->islands[$currentNumber] = $island;

            return true;
        }

        return false;
    }

    /**
     * 同盟在籍ユーザの更新
     * @param  [type] &$hako [description]
     * @return bool          更新があった場合true
     */
    public function allyMemberDel(&$hako)
    {
        $flg = false;

        for ($i=0; $i<$hako->allyNumber; $i++) {
            $count = 0;
            $allyMembers = $hako->ally[$i]['memberId'];
            $newAllyMembers = [];
            foreach ($allyMembers as $id) {
                if ($hako->idToNumber[$id] > -1) {
                    array_push($newAllyMembers, $id);
                    $count++;
                }
            }
            if ($count != $hako->ally[$i]['number']) {
                $hako->ally[$i]['memberId'] = $newAllyMembers;
                $hako->ally[$i]['number'] = $count;
                $flg = true;
            }
        }

        return $flg;
    }

    /**
     * 同盟ごとの所属人口集計
     * [TODO] ターン処理にも挟む
     * @param  [type] &$hako [description]
     * @return bool          更新があった場合true
     */
    public function allyPopComp(&$hako)
    {
        $flg = false;

        for ($i=0; $i<$hako->allyNumber; $i++) {
            $score = 0;
            $allyMembers = $hako->ally[$i]['memberId'];
            foreach ($allyMembers as $id) {
                $island = $hako->islands[$hako->idToNumber[$id]];
                $score += $island['pop'];
            }
            if ($score != $hako->ally[$i]['score']) {
                $hako->ally[$i]['score'] = $score;
                $flg = true;
            }
        }

        return $flg;
    }
}

//------------------------------------------------------------
// Ally
//------------------------------------------------------------
class Ally extends AllyIO
{
    public $islandList;    // 島リスト
    public $targetList;    // ターゲットの島リスト
    public $defaultTarget;    // 目標補足用ターゲット

    /**
     * [readIslands description]
     * @param  [type] $cgi [description]
     * @return [type]      [description]
     */
    public function readIslands(&$cgi)
    {
        global $init;

        $m = $this->readIslandsFile();
        $this->islandList = $this->getIslandList($cgi->dataSet['defaultID'] ?? 0);

        if ($init->targetIsland == 1) {
            // 目標の島 所有の島が選択されたリスト
            $this->targetList = $this->islandList;
        } else {
            // 順位がTOPの島が選択された状態のリスト
            $this->targetList = $this->getIslandList($cgi->dataSet['defaultTarget']);
        }

        return $m;
    }

    //--------------------------------------------------
    // 島リスト生成
    //--------------------------------------------------
    public function getIslandList($select = 0)
    {
        global $init;

        $list = "";
        for ($i = 0; $i < $this->islandNumber; $i++) {
            // 同盟マークを追加
            $name = ($init->allyUse) ? Util::islandName($this->islands[$i], $this->ally, $this->idToAllyNumber) : $this->islands[$i]['name'];
            $id   = $this->islands[$i]['id'];
            // 攻撃目標をあらかじめ自分の島にする
            if (empty($this->defaultTarget)) {
                $this->defaultTarget = $id;
            }
            $s = ($id == $select) ? "selected" : "";
            // 同盟マークを追加
            $list .= ($init->allyUse) ? "<option value=\"$id\" $s>{$name}</option>\n" : "<option value=\"$id\" $s>{$name}{$init->nameSuffix}</option>\n";
        }

        return $list;
    }
}

//------------------------------------------------------------
// AllyIO
//------------------------------------------------------------
class AllyIO
{
    public $islandTurn;     // ターン数
    public $islandLastTime; // 最終更新時刻
    public $islandNumber;   // 島の総数
    public $islandNextID;   // 次に割り当てる島ID
    public $islands;        // 全島の情報を格納
    public $idToNumber;
    public $allyNumber;     // 同盟の総数
    public $ally;           // 各同盟の情報を格納
    public $idToAllyNumber; // 同盟

    //--------------------------------------------------
    // 同盟データ読みこみ
    //--------------------------------------------------
    public function readAllyFile()
    {
        global $init;

        $fileName = $init->dirName.'/'.$init->allyData;
        if (!is_file($fileName)) {
            return false;
        }
        $fp = fopen($fileName, "r");
        Util::lock_on_read($fp);
        $this->allyNumber = chop(fgets($fp, READ_LINE));
        if ($this->allyNumber == '') {
            $this->allyNumber = 0;
        }
        for ($i = 0; $i < $this->allyNumber; $i++) {
            $this->ally[$i] = $this->readAlly($fp);
            $this->idToAllyNumber[$this->ally[$i]['id']] = $i;
        }
        // 加盟している同盟のIDを格納
        for ($i = 0; $i < $this->allyNumber; $i++) {
            $member = $this->ally[$i]['memberId'];
            foreach ($member as $id) {
                $n = $this->idToNumber[$id];
                if (!($n > -1)) {
                    continue;
                }
                array_push($this->islands[$n]['allyId'], $this->ally[$i]['id']);
            }
        }
        Util::unlock($fp);

        return true;
    }
    //--------------------------------------------------
    // 同盟ひとつ読みこみ
    //--------------------------------------------------
    public function readAlly($fp)
    {
        $name       = chop(fgets($fp, READ_LINE));
        $mark       = chop(fgets($fp, READ_LINE));
        $color      = chop(fgets($fp, READ_LINE));
        $id         = chop(fgets($fp, READ_LINE));
        $ownerName  = chop(fgets($fp, READ_LINE));
        $password   = chop(fgets($fp, READ_LINE));
        $score      = chop(fgets($fp, READ_LINE));
        $number     = chop(fgets($fp, READ_LINE));
        $occupation = chop(fgets($fp, READ_LINE));
        $tmp        = chop(fgets($fp, READ_LINE));
        $allymember = explode(",", $tmp);
        $tmp        = chop(fgets($fp, READ_LINE));
        $ext        = explode(",", $tmp); // 拡張領域
        $comment    = chop(fgets($fp, READ_LINE));
        $title      = chop(fgets($fp, READ_LINE));
        list($title, $message) = array_pad(explode("<>", $title), 2, null);

        return [
            'name'       => $name,
            'mark'       => $mark,
            'color'      => $color,
            'id'         => $id,
            'oName'      => $ownerName,
            'password'   => $password,
            'score'      => $score,
            'number'     => $number,
            'occupation' => $occupation,
            'memberId'   => $allymember,
            'ext'        => $ext,
            'comment'    => $comment,
            'title'      => $title,
            'message'    => $message,
        ];
    }
    //--------------------------------------------------
    // 同盟データ書き込み
    //--------------------------------------------------
    public function writeAllyFile()
    {
        global $init;

        $fileName = "$init->dirName/$init->allyData";
        if (!is_file($fileName)) {
            touch($fileName);
        }
        $fp = fopen($fileName, "w");
        Util::lock_on_write($fp);
        fputs($fp, $this->allyNumber . "\n");

        for ($i = 0; $i < $this->allyNumber; $i++) {
            $this->writeAlly($fp, $this->ally[$i]);
        }
        Util::unlock($fp);

        return true;
    }

    //--------------------------------------------------
    // 同盟ひとつ書き込み
    //--------------------------------------------------
    public function writeAlly($fp, $ally)
    {
        $ally_members = join(",", $ally['memberId']);
        $ext = join(",", $ally['ext']);
        $comment = $ally['comment'] ?? '';
        $message = (isset($ally['title']) && isset($ally['message']))
            ? $ally['title'] . '<>' . $ally['message']
            : '<>';

        fwrite(
            $fp,
            <<<EOL
{$ally['name']}
{$ally['mark']}
{$ally['color']}
{$ally['id']}
{$ally['oName']}
{$ally['password']}
{$ally['score']}
{$ally['number']}
{$ally['occupation']}
$ally_members
$ext
$comment
$message

EOL
        );
    }

    //---------------------------------------------------
    // 全島データを読み込む
    //---------------------------------------------------
    public function readIslandsFile()
    {
        global $init;

        $fileName = "{$init->dirName}/hakojima.dat";
        if (!is_file($fileName)) {
            return false;
        }
        $fp = fopen($fileName, "r");
        Util::lock_on_read($fp);
        $this->islandTurn     = chop(fgets($fp, READ_LINE));
        $this->islandLastTime = chop(fgets($fp, READ_LINE));
        $this->islandNumber   = chop(fgets($fp, READ_LINE));
        $this->islandNextID   = chop(fgets($fp, READ_LINE));

        for ($i = 0; $i < $this->islandNumber; $i++) {
            $this->islands[$i] = $this->readIsland($fp);
            $this->idToNumber[$this->islands[$i]['id']] = $i;
            $this->islands[$i]['allyId'] = [];
        }
        Util::unlock($fp);

        if ($init->allyUse) {
            $this->readAllyFile();
        }

        return true;
    }

    //---------------------------------------------------
    // 島ひとつ読み込む
    //---------------------------------------------------
    public function readIsland($fp)
    {
        $name     = chop(fgets($fp, READ_LINE));

        list($name, $owner, $monster, $port, $passenger, $fishingboat, $tansaku, $senkan, $viking) = array_pad(explode(",", $name), 10, null);
        $id       = chop(fgets($fp, READ_LINE));
        list($id, $starturn) = explode(",", $id);
        $prize    = chop(fgets($fp, READ_LINE));
        $absent   = chop(fgets($fp, READ_LINE));
        $comment  = chop(fgets($fp, READ_LINE));
        list($comment, $comment_turn) = explode(",", $comment);
        $password = chop(fgets($fp, READ_LINE));
        $point    = chop(fgets($fp, READ_LINE));
        list($point, $pots) = explode(",", $point);
        $eisei    = chop(fgets($fp, READ_LINE));
        list($eisei0, $eisei1, $eisei2, $eisei3, $eisei4, $eisei5) = array_pad(explode(",", $eisei), 6, null);
        $zin      = chop(fgets($fp, READ_LINE));
        list($zin0, $zin1, $zin2, $zin3, $zin4, $zin5, $zin6) = array_pad(explode(",", $zin), 7, null);
        $item     = chop(fgets($fp, READ_LINE));
        list($item0, $item1, $item2, $item3, $item4, $item5, $item6, $item7, $item8, $item9, $item10, $item11, $item12, $item13, $item14, $item15, $item16, $item17, $item18, $item19) = array_pad(explode(",", $item), 20, null);
        $money    = chop(fgets($fp, READ_LINE));
        list($money, $lot, $gold) = array_pad(explode(",", $money), 3, null);
        $food     = chop(fgets($fp, READ_LINE));
        list($food, $rice) = explode(",", $food);
        $pop      = chop(fgets($fp, READ_LINE));
        list($pop, $peop) = explode(",", $pop);
        $area     = chop(fgets($fp, READ_LINE));
        $job      = chop(fgets($fp, READ_LINE));
        list($farm, $factory, $commerce, $mountain, $hatuden) = explode(",", $job);
        $power    = chop(fgets($fp, READ_LINE));
        list($taiji, $rena, $fire) = explode(",", $power);
        $tenki    = chop(fgets($fp, READ_LINE));
        $soccer   = chop(fgets($fp, READ_LINE));
        list($soccer, $team, $shiai, $kachi, $make, $hikiwake, $kougeki, $bougyo, $tokuten, $shitten) = array_pad(explode(",", $soccer), 10, null);

        return [
            'name'         => $name,
            'owner'        => $owner,
            'id'           => $id,
            'starturn'     => $starturn,
            'prize'        => $prize,
            'absent'       => $absent,
            'comment'      => $comment,
            'comment_turn' => $comment_turn,
            'password'     => $password,
            'point'        => $point,
            'pots'         => $pots,
            'money'        => $money,
            'lot'          => $lot,
            'gold'         => $gold,
            'food'         => $food,
            'rice'         => $rice,
            'pop'          => $pop,
            'peop'         => $peop,
            'area'         => $area,
            'farm'         => $farm,
            'factory'      => $factory,
            'commerce'     => $commerce,
            'mountain'     => $mountain,
            'hatuden'      => $hatuden,
            'monster'      => $monster,
            'taiji'        => $taiji,
            'rena'         => $rena,
            'fire'         => $fire,
            'tenki'        => $tenki,
            'soccer'       => $soccer,
            'team'         => $team,
            'shiai'        => $shiai,
            'kachi'        => $kachi,
            'make'         => $make,
            'hikiwake'     => $hikiwake,
            'kougeki'      => $kougeki,
            'bougyo'       => $bougyo,
            'tokuten'      => $tokuten,
            'shitten'      => $shitten,
            'land'         => $land ?? '',
            'landValue'    => $landValue ?? '',
            'command'      => $command ?? '',
            'port'         => $port ?? '',
            'ship'         => ['passenger' => $passenger, 'fishingboat' => $fishingboat, 'tansaku' => $tansaku, 'senkan' => $senkan, 'viking' => $viking],
            'eisei'        => [0 => $eisei0, 1 => $eisei1, 2 => $eisei2, 3 => $eisei3, 4 => $eisei4, 5 => $eisei5],
            'zin'          => [0 => $zin0, 1 => $zin1, 2 => $zin2, 3 => $zin3, 4 => $zin4, 5 => $zin5, 6 => $zin6],
            'item'         => [0 => $item0, 1 => $item1, 2 => $item2, 3 => $item3, 4 => $item4, 5 => $item5, 6 => $item6, 7 => $item7, 8 => $item8, 9 => $item9, 10 => $item10, 11 => $item11, 12 => $item12, 13 => $item13, 14 => $item14, 15 => $item15, 16 => $item16, 17 => $item17, 18 => $item18, 19 => $item19],
        ];
    }
}



/**
 * class Main
 */
class Main
{
    public $mode;
    public $dataSet = [];
    private $filter_flag = FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_BACKTICK;

    /**
     * 処理分岐
     * @return
     */
    public function execute()
    {
        global $init;

        $ally = new Ally;
        $cgi = new Cgi;

        $this->parseInputData();
        $cgi->getCookies();

        if (!$ally->readIslands($cgi)) {
            HTML::header();
            HakoError::noDataFile();
            HTML::footer();
            exit();
        }
        $cgi->setCookies();

        $html = new HtmlAlly;
        $com = new MakeAlly;

        switch ($this->mode) {
            // 同盟の結成・変更・解散・加盟・脱退
            case "JoinA":
                $html->header();
                $html->newAllyTop($ally, $this->dataSet);
                $html->footer();

                break;

            case "register":
                $html->header();
                $html->register($ally, $this->dataSet);
                $html->footer();

                break;

            case 'confirm':
                $model = new Hakoniwa\Model\Alliance;
                $model->confirm($ally, $this->dataSet);

                break;

            case 'establish':
                $model = new Hakoniwa\Model\Alliance;
                $progress_error = false;
                if ($model->confirm($ally, $this->dataSet, true)) {
                    $model->establish($ally, $this->dataSet);
                } else {
                    $progress_error = true;
                }

                $html->header();
                $_ = $progress_error ? HakoError::probrem() : Success::standard();
                $html->allyTop($ally, $this->dataSet);
                $html->footer();

                break;

            // 同盟の結成・変更
            case "newally":
                $html->header();
                $com->makeAllyMain($ally, $this->dataSet);
                $html->footer();

                break;

            // 同盟の解散
            case "delally":
                $html->header();
                $com->delete_alliance($ally, $this->dataSet);
                $html->footer();

                break;

            // 同盟の加盟・脱退
            case "inoutally":
                $html->header();
                $com->joinAllyMain($ally, $this->dataSet);
                $html->footer();

                break;

            // コメントの変更
            case "Allypact":
                $html->header();
                $html->tempAllyPactPage($ally, $this->dataSet);
                $html->footer();

                break;

            // コメントの更新
            case "AllypactUp":
                $html->header();
                $com->allyPactMain($ally, $this->dataSet);
                $html->footer();

                break;

            // 同盟の情報
            case "AmiOfAlly":
            case 'detail':
                $html->header();
                $html->detail($ally, $this->dataSet);
                $html->footer();

                break;

            default:
                // 箱庭データとのデータ統合処理（ターン処理に組み込んでいないため）
                if ($com->allyReComp($ally)) {
                    break;
                }
                $html->header();
                $html->allyTop($ally, $this->dataSet);
                $html->footer();

            break;
        }
    }
    //---------------------------------------------------
    // POST、GETのデータを取得
    //---------------------------------------------------
    private function parseInputData()
    {
        global $init;

        $this->mode = $_POST['mode'] ?? '';

        if (!empty($_POST)) {
            while (list($name, $value) = each($_POST)) {
                $this->dataSet[$name] = str_replace(",", "", $value);
            }
            if (isset($this->dataSet['Allypact'])) {
                $this->mode = "AllypactUp";
            }
            if (array_key_exists('NewAllyButton', $_POST)) {
                $this->mode = "newally";
            }
            if (array_key_exists('DeleteAllyButton', $_POST)) {
                $this->mode = "delally";
            }
            if (array_key_exists('JoinAllyButton', $_POST)) {
                $this->mode = "inoutally";
            }
        }
        if (filter_has_var(INPUT_GET, 'p')) {
            $this->mode = filter_input(INPUT_GET, 'p', FILTER_SANITIZE_STRING, $this->filter_flag);
            // $this->dataSet['ALLYID'] = ;
        }
        if (!empty($_GET['JoinA'])) {
            $this->mode = "JoinA";
            $this->dataSet['ALLYID'] = $_GET['JoinA'];
        }
        if (!empty($_GET['AmiOfAlly'])) {
            $this->mode = "AmiOfAlly";
            $this->dataSet['ALLYID'] = $_GET['AmiOfAlly'];
        }
        if (!empty($_GET['Allypact'])) {
            $this->mode = "Allypact";
            $this->dataSet['ALLYID'] = $_GET['Allypact'];
        }
    }
}



$start = new Main;
$start->execute();
