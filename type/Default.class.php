<?php

# Collectd Default type

class Type_Default {
	var $datadir;
	var $rrdtool;
	var $cache;
	var $args;
	var $seconds;
	var $data_sources = array('value');
	var $order;
	var $ds_names;
	var $colors;
	var $rrd_title;
	var $rrd_vertical;
	var $rrd_format;
	var $scale;
	var $width;
	var $heigth;

	var $files;
	var $tinstances;
	var $identifiers;

	function __construct($config) {
		$this->datadir = $config['datadir'];
		$this->rrdtool = $config['rrdtool'];
		$this->cache = $config['cache'];
		$this->parse_get();
		$this->rrd_files();
		$this->identifiers = $this->file2identifier($this->files);
	}

	# parse $_GET values
	function parse_get() {
		$this->args = array(
			'host' => $_GET['h'],
			'plugin' => $_GET['p'],
			'pinstance' => $_GET['pi'],
			'type' => $_GET['t'],
			'tinstance' => $_GET['ti'],
		);
		$this->seconds = $_GET['s'];
	}

	function validate_color($color) {
		if (!preg_match('/^[0-9a-f]{6}$/', $color))
			return '000000';
		else
			return $color;
	}

	function get_faded_color($fgc, $bgc='ffffff', $percent=0.25) {
		$fgc = $this->validate_color($fgc);
		if (!is_numeric($percent))
			$percent=0.25;

		$rgb = array('r', 'g', 'b');

		$fg[r] = hexdec(substr($fgc,0,2));
		$fg[g] = hexdec(substr($fgc,2,2));
		$fg[b] = hexdec(substr($fgc,4,2));
		$bg[r] = hexdec(substr($bgc,0,2));
		$bg[g] = hexdec(substr($bgc,2,2));
		$bg[b] = hexdec(substr($bgc,4,2));

		foreach ($rgb as $pri) {
			$c[$pri] = dechex(round($percent * $fg[$pri]) + ((1.0 - $percent) * $bg[$pri]));
			if ($c[$pri] == '0')
				$c[$pri] = '00';
		}

		return $c[r].$c[g].$c[b];
	}

	function rrd_files() {
		$files = $this->get_filenames();

		foreach($files as $filename) {
			$basename=basename($filename,'.rrd');
			$instance=substr($basename,strpos($basename,'-')+1);

			$this->tinstances[] = $instance;
			$this->files[$instance] = $filename;
		}

		sort($this->tinstances);
		ksort($this->files);
	}

	function get_filenames() {
		$identifier = sprintf('%s/%s%s%s/%s%s%s', $this->args['host'],
			$this->args['plugin'], strlen($this->args['pinstance']) ? '-' : '', $this->args['pinstance'],
			$this->args['type'], strlen($this->args['tinstance']) ? '-' : '', $this->args['tinstance']);

		$wildcard = strlen($this->args['tinstance']) ? '' : '*';

		$files = glob($this->datadir .'/'. $identifier . $wildcard . '.rrd');

		return $files;
	}

	function file2identifier($files) {
		foreach($files as $key => $file) {
			if (is_file($file)) {
				$files[$key] = basename($files[$key], '.rrd');
			}
		}

		return $files;
	}

	function rrd_graph($debug=false) {
		$graphdata = $this->rrd_gen_graph();
		
		if(!$debug) {
			# caching
			if (is_numeric($this->cache) && $this->cache > 0)
				header("Expires: " . date(DATE_RFC822,strtotime($this->cache." seconds")));
			header("content-type: image/png");
			$graphdata = implode(' ', $graphdata);
			echo `$graphdata`;
		} else {
			print '<pre>';
			print_r($graphdata);
			print '</pre>';
		}
	}

	function rrd_options() {
		$rrdgraph[] = $this->rrdtool;
		$rrdgraph[] = 'graph - -a PNG';
		$rrdgraph[] = sprintf('-w %d', is_numeric($this->width) ? $this->width : 400);
		$rrdgraph[] = sprintf('-h %d', is_numeric($this->heigth) ? $this->heigth : 175);
		$rrdgraph[] = '-l 0';
		$rrdgraph[] = sprintf('-t "%s on %s"', $this->rrd_title, $this->args['host']);
		$rrdgraph[] = sprintf('-v "%s"', $this->rrd_vertical);
		$rrdgraph[] = sprintf('-s -%d', is_numeric($this->seconds) ? $this->seconds : 86400);

		return $rrdgraph;
	}

	function rrd_get_sources() {
		# is the source spread over multiple files?
		if (is_array($this->files) && count($this->files)>1) {
			# and must it be ordered?
			if (is_array($this->order)) {
				$this->tinstances = array_intersect($this->order, $this->tinstances);
			}
			# use tinstances as sources
			if(is_array($this->data_sources) && count($this->data_sources)>1) {
				$sources = array();
				foreach($this->tinstances as $f) {
					foreach($this->data_sources as $s) {
						$sources[] = $f . '-' . $s;
					}
				}
			}
			else {
				$sources = $this->tinstances;
			}
			$sources = str_replace('.', '_', $sources);
		}
		# or one file with multiple data_sources
		else {
			# use data_sources as sources
			$sources = $this->data_sources;
		}
		return $sources;
	}

	function rrd_gen_graph() {
		$rrdgraph = $this->rrd_options();

		$sources = $this->rrd_get_sources();

		$i=0;
		foreach ($this->tinstances as $tinstance) {
			foreach ($this->data_sources as $ds) {
				$rrdgraph[] = sprintf('DEF:min_%s="%s":%s:MIN', crc32hex($sources[$i]), $this->files[$tinstance], $ds);
				$rrdgraph[] = sprintf('DEF:avg_%s="%s":%s:AVERAGE', crc32hex($sources[$i]), $this->files[$tinstance], $ds);
				$rrdgraph[] = sprintf('DEF:max_%s="%s":%s:MAX', crc32hex($sources[$i]), $this->files[$tinstance], $ds);
				$i++;
			}
		}

		if(count($this->files)<=1) {
			foreach ($sources as $source) {
				$rrdgraph[] = sprintf('AREA:max_%s#%s', crc32hex($source), $this->get_faded_color($this->colors[$source]));
				$rrdgraph[] = sprintf('AREA:min_%s#%s', crc32hex($source), 'ffffff');
				break; # only 1 area to draw
			}
		}

		foreach ($sources as $source) {
			$dsname = $this->ds_names[$source] != '' ? $this->ds_names[$source] : $source;
			$color = is_array($this->colors) ? $this->colors[$source]: $this->colors;
			$rrdgraph[] = sprintf('LINE1:avg_%s#%s:\'%s\'', crc32hex($source), $this->validate_color($color), $dsname);
			$rrdgraph[] = sprintf('GPRINT:min_%s:MIN:\'%s Min,\'', crc32hex($source), $this->rrd_format);
			$rrdgraph[] = sprintf('GPRINT:avg_%s:AVERAGE:\'%s Avg,\'', crc32hex($source), $this->rrd_format);
			$rrdgraph[] = sprintf('GPRINT:max_%s:MAX:\'%s Max,\'', crc32hex($source), $this->rrd_format);
			$rrdgraph[] = sprintf('GPRINT:avg_%s:LAST:\'%s Last\\l\'', crc32hex($source), $this->rrd_format);
		}

		return $rrdgraph;
	}
}

?>
