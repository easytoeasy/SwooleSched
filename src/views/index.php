<?php

use Swoole\Timer;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <title>Swoole Sched定时任务</title>
  <link href="css/supervisor.css" rel="stylesheet" type="text/css">
  <link href="images/icon.png" rel="icon" type="image/png">
</head>

<body>
  <div id="wrapper">

    <div id="header">
      <img alt="Supervisor status" src="images/supervisor.gif">
    </div>

    <div>
      <div class="status_msg">
        <?= $this->message ?>
        <span style="float:right">
          <font style="color: gray;">
            serverId:<?= SERVER_ID ?>,
            ppid:<?= getmypid() ?>,
            createAt:<?= $this->createAt ?>,
            outofMin: <?= $this->outofMin ?>,
            beDelIds:<?= count($this->beDelIds) ?>,
            childs:<?= count($this->childpids) ?>,
            timers:<?= count($this->timerIds) . '=' . count(Timer::list()) ?>,
          </font>
        </span>
      </div>

      <ul class="clr" id="buttons">
        <li class="action-button"><a href="tail.php" target="_blank">tail log</a></li>
        <li class="action-button"><a href="index.html?action=flush">flush db cache</a></li>
        <li class="action-button">
          <select id='tagid' onchange="tagChange()">
            <option value="0">全部</option>
            <?php

            use pzr\swoolesched\State;

            $searchTagid = isset($_GET['tagid']) ? $_GET['tagid'] : 0;
            foreach ($this->servTags as $id => $name) {
              $selected = '';
              if ($searchTagid == $id) {
                $selected = 'selected';
              }
              printf("<option %s value=%s>%s</option>", $selected, $id, $name);
            }
            ?>
          </select>
        </li>
        <li class="action-button"><a href="index.html?action=clear">clear log</a></li>
      </ul>

      <table cellspacing="0">
        <thead>
          <tr>
            <th class="state">State</th>
            <th class="desc">Description</th>
            <th class="name">Name</th>
            <th class="action">Action</th>
          </tr>
        </thead>

        <tbody>
          <?php

          $id = $_GET['id'] ?? 0;
          if ($this->jobs)
            /** @var Job $c */
            foreach ($this->jobs as $c) {
              if ($searchTagid && $c->tag_id != $searchTagid) {
                continue;
              }
              if ($id && $c->id != $id) continue;
          ?>
            <tr class="shade">
              <td class="status"><span class="status<?= State::css($c->state) ?>"><?= State::desc($c->state) ?></span></td>
              <td>
                <span>pid <?= $c->pid ?>, refcount: <?= $c->refcount ?></span>
              </td>
              <td><?= $c->id ?>, overtime:<?= $c->outofCron ?>, <a href="stderr.php?md5=<?= $c->md5 ?>&type=1" target="_blank"><?= $c->name ?></a> </td>
              <td class="action">
                <ul>
                  <?php if (in_array($c->state, State::runingState())) { ?>
                    <li>
                      <a href="index.html?md5=<?= $c->md5 ?>&action=stop" name="Stop">Stop</a>
                    </li>
                  <?php } else { ?>
                    <li>
                      <a href="index.html?md5=<?= $c->md5 ?>&action=start" name="Start">Start</a>
                    </li>
                  <?php } ?>
                  <?php if (is_numeric($c->cron) && isset($this->timerIds[$c->id])) { ?>
                    <a href="index.html?md5=<?= $c->md5 ?>&action=clearTimer" name="clearTimer">clearTimer</a>
                  <?php } ?>
                  <li <a href="stderr.php?md5=<?= $c->md5 ?>&type=2" name="Tail -f Stderr" target="_blank">Tail -f Stderr</a>
                  </li>
                </ul>
              </td>
            </tr>
            <tr>
              <td colspan="4">
                <font style="color: gray;margin-left:77px;">
                  <?= $c->uptime . '~' . $c->endtime ?>&nbsp;&nbsp;|&nbsp;&nbsp;
                  <?php if (is_numeric($c->cron)) {
                    echo '<font color=red>' . $c->cron . 'ms</font>';
                  } ?> <?= $c->command ?>
                </font>
              </td>
            </tr>
          <?php  } ?>
        </tbody>
      </table>

    </div>
</body>
<script>
  function tagChange() {
    var obj = document.getElementById('tagid'); //定位id
    var value = obj.value; // 选中值
    window.location.href = 'index.php?tagid=' + value;
  }

  function loggerChange() {
    var obj = document.getElementById('level'); //定位id
    var value = obj.value; // 选中值
    window.location.href = 'index.html?level=' + value;
  }
</script>

</html>