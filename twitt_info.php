<?php


error_reporting (E_ERROR);
define( "DOCROOT", dirname(__FILE__) ."/" );

include_once( DOCROOT ."includes/functions.class.php" );
include_once( "includes/dbconfig.php" );

if (isset($_POST["ajax"]) && $_POST["ajax"]=="start")
{
      $p = new ParseTwitt;
      $res = $p->getLoginsTwitt();
      $s = "";
      while ($row = mysql_fetch_assoc($res))
      {
            $s .= $row["id"]."**".$row["twitter_login"]."-|-";
      }
      $s = substr($s, 0, strlen($s)-3);
      echo $s;
      die();
}

if (isset($_POST["ajax"]) && $_POST["ajax"]=="recurs")
{
      $p = new ParseTwitt;
      $id = $_POST["_id"];
      $title = $_POST["_title"];
      
      $page = $p->funcs->url_open( $p->start_url . $title );
      if (!$page)
      {
            for ($i=0; $i < 20; $i++) { 
                 $page = $p->funcs->url_open( $p->start_url . $title );
                 if ($page!="") 
                        break;
            }
      }
      
      if (!$page)
      {
            //diagnostic messages
            // echo "empty page! ".$p->start_url . $title;
            echo "empty ".$title;
            die();
      }

      $info = $p->getInfo( $page, $title );
      if (!isset($info["followers"])) 
      {
            //diagnostic messages
            // echo "empty".$p->start_url . $title." ".$_POST["_id"]." ".$_POST["_title"];
            // echo "<br>";
            // print_r($info);
            echo "empty ".$title;
            die();
      }
      else
      {
            echo $_POST["_title"];
            echo "<br>";
            print_r($info);
            echo "<br>";
            echo str_repeat("=", 120);
            echo "<br>";
      }
      $p->updateInfoTwitt( $info, $id );
      
      die("");
}

class ParseTwitt{

      var $start_url = "https://twitter.com/";
      var $auth_url = "https://twitter.com/sessions";
      var $table = "dle_post";


      function __construct(){
            $this->funcs = new Functions;
            $this->config = $this->funcs->getConfig( 'general' );
            if( !$this->config['username'] OR !$this->config['passwd'] ) {
                  die( "Заполните данные для авторизации на twitter.com" );
            }
            if( !intval($this->config['time_auth']) OR (time() - intval($this->config['time_auth'])) > 3600 ){
                  if( !$this->auth() ){
                        $this->logs( "Неправильные данные для доступа в твиттер" );
                        $this->auth_status = FALSE;
                  }else{
                        $this->config['time_auth'] = time();
                        $this->funcs->saveConfig( "general", $this->config );
                        $this->auth_status = TRUE;
                  }
            }else{
                  $this->auth_status = TRUE;
            }
      }


      function auth( $redirect_url="/" ){
            $page = $this->funcs->url_open( $this->start_url );
            if( stripos($page, 'name="session[password]"') === FALSE ) return TRUE;
            $post_data = "session[username_or_email]=". rawurlencode($this->config['username']) ."&session[password]=". rawurlencode($this->config['passwd']);
            preg_match( '#<input type="hidden" value="(.[^"]+?)" name="authenticity_token"/>#', $page, $auth_token );
            $post_data .= "&authenticity_token=". rawurlencode($auth_token[1]) ."&redirect_after_login=". rawurlencode($redirect_url) ."&scribe_log=";
            $post_data .= "&remember_me=1";
            $page = $this->funcs->url_open( $this->auth_url, array("post_data" => $post_data) );
            if( stripos($page, 'name="session[password]"') === FALSE ) return TRUE;
      }


      function parseTwitts( $page ){
            // echo $page;
            // die();

            if( !$page ) return;
            preg_match( '#data-element-term="tweet_stats" data-nav="profile" >\s{0,200}<strong>(.[^>]+?)</strong>#', $page, $value );
            if (empty($value))
            {
                  preg_match( '#data-element-term="tweet_stats" data-nav="profile" >\s{0,200}<strong>([\s*\d+])</strong>#', $page, $value );
            }

            if( !$value ) return;
            return trim( $value[1] );
      }


      function parseFollowing( $page ){
            // echo $page;
            if( !$page ) return;
            preg_match( '#data-element-term="following_stats" data-nav="following" >\s{0,200}<strong>(.[^>]+?)</strong>#', $page, $value );
            if (empty($value))
            {
                  preg_match( '#data-element-term="following_stats" data-nav="following" >\s{0,200}<strong>([\s*\d+])</strong>#', $page, $value );
            }
            if( !$value ) return;
            return trim( $value[1] );
      }


      function parseFollowers( $page ){

            if( !$page ) return;
            preg_match( '#data-element-term="follower_stats" data-nav="followers" >\s{0,200}<strong>(.[^>]+?)</strong>#', $page, $value );
            if (empty($value))
            {
                  preg_match( '#data-element-term="follower_stats" data-nav="followers" >\s{0,200}<strong>([\s*\d+])</strong>#', $page, $value );
            }
            if( !$value ) return;
            return trim( $value[1] );
      }


      function parseAvatar( $page ){
            if( !$page ) return;
            preg_match( '#<a href="(.[^>]+?)" class="profile-picture media-thumbnail#', $page, $value );

            if( !$value ) return;
            return $value[1];
      }

      function parseLastTweets( $page )
      {
            if( !$page ) return "";
           $dom = new DOMDocument();

           //delete some nodes
           $dom->loadHTML('<?xml encoding="utf-8">'.$page);
           $xpath = new DOMXPath($dom);
           foreach ($xpath->query('//*[@class="stream-item-footer" or @class="bottom-tweet-actions" or @class="cards-media-container js-media-container"]') as $liNode) {
               $liNode->parentNode->removeChild($liNode); 
           }
           $page = $dom->saveHTML($dom->documentElement);


           $dom->loadHTML('<?xml encoding="utf-8">'.$page);
           $xpath = new DOMXPath($dom);
           $res = $xpath->query("//ol[@id='stream-items-id']/li");

           $counter = 0;
           $html = "";
           foreach ($res as $r) {
                 if (++$counter>10)
                       break;
                 $html .= $dom->saveHTML($r);      
            } 
            return $html;
      }

      function parseSearchResults( $login )
      {
            if( !$login ) return "";
            $dom = new DOMDocument();
            $page = $this->funcs->url_open( "https://twitter.com/search?q=@".$login);

            //delete some nodes
            $dom->loadHTML('<?xml encoding="utf-8">'.$page);
            $xpath = new DOMXPath($dom);
            foreach ($xpath->query('//*[@class="stream-item-footer" or @class="bottom-tweet-actions" or @class="cards-media-container js-media-container"]') as $liNode) {
                $liNode->parentNode->removeChild($liNode); 
            }
            $page = $dom->saveHTML($dom->documentElement);

            $dom->loadHTML('<?xml encoding="utf-8">'.$page);
            $xpath = new DOMXPath($dom);
            $res = $xpath->query("//ol[@id='stream-items-id']/li[contains(@id,'stream-item-tweet')]");

            $counter = 0;
            $html_search = "";
            foreach ($res as $r) {
                  if (++$counter>10)
                        break;
                  $html_search .= $dom->saveHTML($r);      
            }
            return $html_search;
      }


      function getInfo( $page, $login = "" ){
            if( !$page ) return;
            $info = array();
            $info['twitts'] = $this->parseTwitts( $page );
            $info['following'] = $this->parseFollowing( $page );
            $info['followers'] = $this->parseFollowers( $page );
            $info['link_img_profile'] = $this->parseAvatar( $page );
            $info['last_tweets'] = $this->parseLastTweets( $page );
            $info['search_results'] = $this->parseSearchResults( $login );

            return $info;
      }

      function getLoginsTwitt(){
            $resource = mysql_query( "SELECT `id`, `twitter_login` FROM `". $this->table ."` WHERE `twitter_login` != '';" ); //# and `twitter_login`='victoriabonya'
            return $resource;
      }

      function getCountLogins()
      {
            $resource = mysql_query( "SELECT count(`id`) FROM `". $this->table ."` WHERE `twitter_login` != '';" );
            $res = mysql_fetch_assoc($resource);
            return $res["count(`id`)"];     
      }


      function fetchArray( $resource ){
            return mysql_fetch_array( $resource );
      }


      function updateInfoTwitt( $info, $id ){
            if( !$info OR !$id ) return;
            mysql_query( "UPDATE `". $this->table ."` SET
                `twitter_twitts` = '". preg_replace("#\D#", '', $info['twitts'])  ."',
                `twitter_following` = '". preg_replace("#\D#", '', $info['following']) ."',
                `twitter_followers` = '". preg_replace("#\D#", '', $info['followers']) ."',
                `twitter_pic` = '". mysql_real_escape_string($info['link_img_profile']) ."',
                `last_tweets` = '". mysql_real_escape_string($info['last_tweets']) ."',
                `search_results` = '". mysql_real_escape_string($info['search_results']) ."'
                WHERE `id` = '". $id ."'
            ;" ) or die(mysql_error());
      }


      function updater(){
            if( !$this->auth_status ) $this->funcs->logs( "Ошибка авторизации" );
            $resource = $this->getLoginsTwitt();
            $i=0;
            while( $row = $this->fetchArray($resource) ){
                  //print_r($row);
                  $twitter_login = $row['twitter_login'];
                  if( !$twitter_login ) continue;
                  $page = $this->funcs->url_open( $this->start_url . $twitter_login );
                  //echo $page;
                  //die();
                  $info = $this->getInfo( $page, $twitter_login );      
                  print_r($info);
                  
                  echo "<br>";
                  
//                   var_dump( $info, $row );continue;
                  
                  $this->updateInfoTwitt( $info, $row['id'] );
                  //sleep( 3 );
            }
      }


}


$p = new ParseTwitt;
//$p->updater();

//echo $p->getCountLogins();

?>


<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.5/jquery.min.js"></script>
<script src="edit_twitter_links.js"></script>
<link rel="stylesheet" href="tweets.css">

<script src="http://ajax.googleapis.com/ajax/libs/jqueryui/1.8/jquery-ui.min.js"></script>
<link href="http://ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery-ui.css" rel="stylesheet" type="text/css"/>

<style type="text/css">
#tabs
{
font-size:0.7em;
height:500px;
}
</style>
<script type="text/javascript">
// function check_progress()
// {
//    $.ajax({
//          url: 'progress.php',
//          type: 'post',
//          dataType: "text",
            
//          success: function (data) {
//                //alert(data)
//                var res = data.split(",")
//                var progres = Math.ceil(parseInt(res[0])/parseInt(res[1]))
                  
//                $("#pbar1").progressbar("value", progres)
//          }
//    });
// }

$(document).ready(function() {
      //setInterval(check_progress, 2000)
      

   $("#pbar1").progressbar({value:0});
   $("#but1,#but2,#but3").button();

   pause = false
   stop_all = false

   function rec_ajax(_counter)
   {

      if (_counter>=size)
      {
            alert("Скрипт завершил свою работу!")
            $("#pbar1").progressbar("value", 100)
            return;
      }
      if (pause)
      {
            alert("Процесс приостановлен")
            $('#but1').removeAttr("disabled");
            $('#but2').attr("disabled", "disabled");
            $('#but3').attr("disabled", "disabled");
            return;
      }
      if (stop_all)
      {
            if (confirm("Вы уверены что хотите полностью прервать процесс?"))
            {
                  stop_all = false
                  flag = false
                  $("#pbar1").progressbar("value", 0)
                  $('#from').html("")
                  $('#to').html("")
                  $('#procents').html("")
                  alert("Процесс полносью прерван")
                  return
            }
            else
            {
                  stop_all = false
            }
            
      }
            
                  $.ajax({
                        url: '<?= basename(__FILE__)  ?>',
                        type: 'post',
                        dataType: "text",
                        data: {ajax: "recurs", _id: ids[_counter], _title: titles[_counter]},
                        success: function (data) {
                              if (data.indexOf("empty")+1)
                              {
                                    var res = data.split(" ") 

                                    var html = $('#diag').html()
                                    html += '<span style="color: red;font-style: italic">Не удалось пропарсить пользователя с логином '+res[1]+'</span><br>';
                                    $('#diag').html(html)
                                    
                              }
                              else
                              {
                                    var html = $('#diag').html()
                                    html += data
                                    $('#diag').html(html)
                              }
                              $("#pbar1").progressbar("value", (_counter/size)*100)
                              $('#from').html(_counter+1)
                              $('#to').html(size)
                              $('#procents').html(Math.ceil((_counter/size)*100))
                              //alert(_counter+" "+(_counter/size)*100)
                              counter = ++_counter
                              rec_ajax(_counter)

                        },
                        error: function(er){
                              alert("jserror 1")
                              //alert(er)
                        },
                  });
   }
   flag = false
   
   $("#but1").click(function(){
      $('#but2').removeAttr("disabled");
      $('#but3').removeAttr("disabled");
      $('#but1').attr("disabled", "disabled");
            if (!pause)
            {
                  $.ajax({
                        url: '<?= basename(__FILE__)  ?>',
                        type: 'post',
                        dataType: "text",
                        data: {ajax: "start"},
                        success: function (data) {
                              if (!flag)
                              {
                                    var res = data.split("-|-")

                                    ids = []

                                    titles = []

                                    for (var i = 0; i < res.length; i++) {
                                          var tmp = res[i].split("**")
                                          ids.push(tmp[0])
                                          titles.push(tmp[1])
                                    }
                                    
                                    counter = 0
                                    flag = true 
                                    size = ids.length
                              }
                              rec_ajax(counter)

                        },
                        error: function(er){
                              alert("jserror 2")
                        },
                  });
            }
            else
            {
                  
                  pause = false
                  rec_ajax(counter)
            }
            
             
      });

   $("#but2").click(function(event) {
            pause = true
            $('#but1').attr("disabled", "disabled");
   });

   $("#but3").click(function(event) {
            stop_all = true
            $('#but1').attr("disabled", "disabled");
            $('#but2').attr("disabled", "disabled");
   });

});
</script>

<!-- <base href="https://twitter.com"> -->

</head>
<body>
<div id="tabs">

<div id="tabs-1">
<div id="pbar1"></div>
<br /><br />
<p style="font-size: 24px">Прогресс <span id="from"></span> из <span id="to"></span> ( <span id="procents"></span> %)
</p>
<br /><br />
<button id="but1">Старт</button>
<button id="but2">Приостановить процесс</button>
<button id="but3">Полностью прервать процесс процесс</button>

</div>
<div id="diag" style="font-size: 14px;">
      
</div>
<h1>Пример вывода твитов</h1>
<!-- ПРИМЕТ ВЫВОДА ТВИТОВ -->
<?php 
      $resource = mysql_query( "SELECT `last_tweets`, `search_results` FROM `dle_post` WHERE `twitter_login` = 'medvedevrussia';" );

      $res = mysql_fetch_assoc($resource);
      
      echo $res["last_tweets"];
      echo $res["search_results"];
 ?>

 </body>
</html>

 




 