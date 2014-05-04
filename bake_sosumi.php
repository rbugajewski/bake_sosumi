#!/usr/bin/php
<?php

require_once('libs/getid3/getid3.php');
require_once('libs/getid3/write.php');
require_once('libs/spyc.php');

$comments_suffix = "\n\nLinks für diese Episode:\n\n";
$mp4v2_path = "/usr/local/Cellar/mp4v2/1.9.1/bin";
#$mp4v2_path = getenv("HOME")."/bin";

$short_options = '';
$long_options = array('episode:', 'title:', 'artist:', 'album:', 'comments:', 'genre:', 'drafts-folder:', 'audio-folder:', 'audio-filename:', 'audio-prefix:');
$options = getopt($short_options, $long_options);
if (!$options)
{
  die("Usage: $argv[0] --episode=episode_number --title=track_title --artist=track_artist --album=track_album --comments=track_comments --genre=track_genre --drafts-folder=/path/to/drafts/output/folder/ --audio-filename==/path/to/audio/input/filename.(mp3|m4a) --audio-folder=/path/to/audio/output/folder/ --audio-prefix=prefix_of_new_audio_filename\n\n");
}

if (!file_exists($options['audio-filename']))
{
  die("Audio file at {$options['audio-filename']} doesn’t exist.\n");
}

$file_extension = pathinfo($options['audio-filename'], PATHINFO_EXTENSION);

if (!(($file_extension == "mp3") || ($file_extension == "m4a")))
{
  die("Only M4A and MP3 files are supported. {$options['audio-filename']} doesn’t seem to be one.\n");
}

if (!file_exists($options['drafts-folder']))
{
  die("Drafts output folder at {$options['drafts-folder']} doesn’t exist.\n");
}

if (!file_exists($options['audio-folder']))
{
  die("Audio output folder at {$options['audio-folder']} doesn’t exist.\n");
}

$episode = parse_int($options['episode']);
$track_filename = $options['audio-prefix'].'-'.$episode.'.'.$file_extension;
$track_full_filename = $options['audio-folder'].$track_filename;

if (file_exists($track_full_filename))
{
  die("Audio file at output folder already exist. Won’t overwrite.\n");
}

if (!copy($options['audio-filename'], $track_full_filename))
{
  die("Couldn’t copy audio file {$options['audio-filename']} to audio output folder {$options['audio-folder']}.\n");
} else
{
  unlink($options['audio-filename']);
}

$post_filename = date('Y-m-d').'-episode-'.$episode.'.markdown';
$post_full_filename = $options['drafts-folder'].$post_filename;

if ($file_extension == "mp3")
{
  $get_id3 = new getID3;
  $get_id3->setOption(array('encoding'=>'UTF-8'));
  $tagwriter = new getid3_writetags;
  $tagwriter->filename = $track_full_filename;
  $tagwriter->tagformats = array('id3v1', 'id3v2.3');
  $tagwriter->overwrite_tags = true;
  $tagwriter->remove_other_tags = true;
  $tagwriter->tag_encoding = 'UTF-8';
  $tag_data = array(
    'title'   => array('#'.$episode.': '.$options['title']),
    'artist'  => array($options['artist']),
    'album'   => array($options['album']),
    'year'    => array(date('Y')),
    'genre'   => array($options['genre']),
    'comment' => array($options['comments']),
    'unsynchronised_lyrics' => array($options['comments']),
    'track'   => array($episode),
  );
  $tagwriter->tag_data = $tag_data;

  if (!$tagwriter->WriteTags())
  {
    die("Failed to write tags.\n".implode('\n\n', $tagwriter->errors)."\n");
  }

  $track_fileinfo = $get_id3->analyze($track_full_filename);
  write_metadata($post_full_filename, $episode, $options['title'], $track_filename, $track_fileinfo['filesize'], $track_fileinfo['playtime_string'], $options['comments'], $comments_suffix);
} else if ($file_extension == "m4a")
{
  $mp4tags_command = "mp4tags";
  $mp4tags_check = shell_exec('command -v '.$mp4tags_command.' >& /dev/null && echo "Found" || echo "Not Found"');
  if(trim($mp4tags_check) != "Found")
  {
    unset($mp4tags_check);
    $mp4tags_command = $mp4v2_path."/mp4tags";
    $mp4tags_check = shell_exec('command -v '.$mp4tags_command.' >& /dev/null && echo "Found" || echo "Not Found"');
  }
  if(trim($mp4tags_check) != "Found")
  {
    die("Could not find mp4tags. Please make sure it’s in your path.'$mp4tags_check'\n");
  }

  $mp4info_command = "mp4info";
  $mp4info_check = shell_exec('command -v '.$mp4info_command.' >& /dev/null && echo "Found" || echo "Not Found"');
  if(trim($mp4info_check) != "Found")
  {
    unset($mp4info_check);
    $mp4info_command = $mp4v2_path."/mp4info";
    $mp4info_check = shell_exec('command -v '.$mp4info_command.' >& /dev/null && echo "Found" || echo "Not Found"');
  }
  if(trim($mp4info_check) != "Found")
  {
    die("Could not find mp4info. Please make sure it’s in your path.\n");
  }

shell_exec($mp4tags_command.' -r w -s "'.'#'.$episode.': '.$options['title'].'" -a "'.$options['artist'].'" -A "'.$options['album'].'" -y "'.date('Y').'" -g "'.$options['genre'].'" -c "'.$options['comments'].'" -L "'.$options['comments'].'" -m "'.$options['comments'].'" -t '.$episode.' "'.$track_full_filename.'"');

$track_playtime_output = shell_exec($mp4info_command.' "'.$track_full_filename.'" | sed -n "s/^.*audio.*, \([0-9]*\)\.[0-9]* secs,.*$/\1/p"');
  write_metadata($post_full_filename, $episode, $options['title'], $track_filename, filesize($track_full_filename), minutes(trim($track_playtime_output)), $options['comments'], $comments_suffix);
}

function write_metadata($post_full_filename, $episode, $title, $track_filename, $track_filesize, $track_playtime, $comments, $comments_suffix)
{
  if (file_exists($post_full_filename))
  {
    die("Posts file at output folder already exist. Won’t overwrite.\n");
  }

  $post_array = array(
    'title'             => 'Episode '.$episode.': '.$title,
    'layout'            => 'post',
    'podcastfilename'   => $track_filename,
    'podcastlength'     => $track_filesize,
    'itunesurl'         => '#ITUNESURL#',
    'length'            => $track_playtime. ' min',
  );
  $yaml_output = Spyc::YAMLDump($post_array, 2, 72);
  $yaml_output.= "---\n\n".$comments.$comments_suffix;

  if (!$handle = fopen($post_full_filename, 'w'))
  {
    die("Cannot open file $post_full_filename");
  }

  if (fwrite($handle, $yaml_output) === FALSE)
  {
    die("Cannot write to file $post_full_filename");
  }

  fclose($handle);
}

function minutes($seconds)
{
  return sprintf("%02.2d:%02.2d", floor($seconds / 60), $seconds % 60);
}

// Take a string and return the first number found.
// http://kzar.co.uk/blog/view/parsing-number-string-php
function parse_int($string)
{
  ereg("([0-9]+)", $string, $results);
  if (is_array($results))
  {
    return (int)$results[0];
  } else
  {
    return NULL;
  }
}
