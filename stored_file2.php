<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

class Stored_File {

    const SAFE_EXTENSION = 'bin';
    const SIZE_GIB = 1073741824;
    const SIZE_MIB = 1048576;
    const SIZE_KIB = 1024;

    protected static $__supported_exts = array(

        'gif',	// image
        'png',	// image
        'jpg',	// image
        'jpeg',	// image
        'doc',	// word document
        'docx',	// word document
        'xls',	// excel spreadsheet
        'xlsx',	// excel spreadsheet
        'ppt',	// powerpoint
        'pptx',	// powerpoint
        'rtf',	// rich text document
        'pdf',	// portable document
        'mp3',   // mp3 audio
        'csv',   // csv data
        'txt',   // text file
        'zip',   // zip file

    );

    public $id;
    public $extension;
    public $mime;
    public $filename;
    public $filename_origin;
    public $source;

    protected $__moved;

    public function actual_filename()
    {
        if ($this->__moved)
            return static::resolve_file($this->filename);
        return $this->source;
    }

    public function has_supported_extension($supported_exts = null)
    {
        if (!$this->extension)
            $this->extension = static::parse_extension($this->filename);

        if (!$this->extension)
            return false;

        if ($supported_exts && is_array($supported_exts) && count($supported_exts))
        {
            return in_array($this->extension,
                $supported_exts);
        }

        return in_array($this->extension,
            static::$__supported_exts);
    }

    public function exists()
    {
        return is_file($this->actual_filename());
    }

    public function size()
    {
        return filesize($this->actual_filename());
    }

    public function human_size()
    {
        $size = $this->size();

        if ($size > static::SIZE_GIB)
        {
            $size = ($size / static::SIZE_GIB);
            return sprintf('%.2f GiB', $size);
        }

        if ($size > static::SIZE_MIB)
        {
            $size = ($size / static::SIZE_MIB);
            return sprintf('%.2f MiB', $size);
        }

        $size = ($size / static::SIZE_KIB);
        return sprintf('%.2f KiB', $size);
    }

    public function detect_mime()
    {
        if ($this->mime) return $this->mime;
        $this->mime = File_Util::detect_mime($this->actual_filename());
        return $this->mime;
    }

    public function generate_filename($ext = null)
    {
        if (!$ext) $ext = $this->extension;

        $hash = md5_file($this->source);
        $dir1 = substr($hash, 0, 2);
        $dir2 = substr($hash, 2, 2);
        $name = substr($hash, 4, 28);

        $this->filename_origin = build_path($dir1, $dir2, sf('%s.%s', $name, $ext));
        $this->filename = build_path($dir1, $dir2, $name . uniqid( '.', true) . ".$ext");

        return $this->filename;
    }

    public function read()
    {
        return file_get_contents($this->actual_filename());
    }

    public function move()
    {
        if ($this->__moved) return;
        $origin = static::resolve_file($this->filename_origin, true);
        $target = static::resolve_file($this->filename, true);
        $dir = dirname($target);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (file_exists($origin)) {
            link($origin, $target);
        } else {
            $this->filename = $this->filename_origin;
            rename($this->source, $origin);
        }
        $this->__moved = true;
    }

    public function delete()
    {
        $filename = $this->actual_filename();

        // Safe delete to avoid old filename patterns
        if (substr_count($filename, '.') >= 2) return unlink($filename);

        return true;
    }

    public function url()
    {
        return static::url_from_filename($this->filename);
    }

    public function save_to_db()
    {
        if (!$this->__moved)
            $this->move();

        // might already exist
        // so we IGNORE errors here
        $ci =& get_instance();
        $ci->db->query("INSERT IGNORE INTO
			nr_stored_file (filename) VALUES (?)",
            array($this->filename));

        if (!($id = $ci->db->insert_id()))
        {
            // no id? must already exist within database
            // so we can lookup the existing ID and use that
            $query = $ci->db->query("SELECT id FROM nr_stored_file
				WHERE filename = ?", array($this->filename));
            $result = $query->row();
            $this->id = $id = $result->id;
        }

        return $id;
    }

    public static function parse_extension($filename, $default = null)
    {
        if ($default === null)
            $default = static::SAFE_EXTENSION;

        // if no extension return a safe .bin extension
        if (strpos($filename, '.') === false)
            return $default;

        // parse extension and check it is allowed
        $parts = explode('.', basename($filename));
        $extension = strtolower(end($parts));
        if (!in_array($extension, static::$__supported_exts))
            return $default;

        return $extension;
    }

    public static function from_file($source, $default_ext = null)
    {
        if (!is_file($source))
            return new static();

        $file = new static();
        $file->source = $source;
        $file->extension = static::parse_extension($source, $default_ext);
        $file->generate_filename();
        return $file;
    }

    public static function from_uploaded_file($name, $default_ext = null)
    {
        if (!isset($_FILES[$name]))
            return new static();

        // multiple files uploaded with same name
        if (is_array($_FILES[$name]['tmp_name']))
        {
            $uploads = array();

            // loop over each of the multiple files and create
            foreach ($_FILES[$name]['tmp_name'] as $k => $tmp_name)
            {
                if (!is_uploaded_file($_FILES[$name]['tmp_name'][$k]))
                {
                    $uploads[] = new static();
                    continue;
                }

                $upload = new static();
                $upload->source = $_FILES[$name]['tmp_name'][$k];
                $upload->extension = static::parse_extension(
                    $_FILES[$name]['name'][$k], $default_ext);
                $upload->generate_filename();
                $uploads[] = $upload;
            }

            return $uploads;
        }

        if (!is_uploaded_file($_FILES[$name]['tmp_name']))
            return new static();

        $upload = new static();
        $upload->source = $_FILES[$name]['tmp_name'];
        $upload->extension = static::parse_extension(
            $_FILES[$name]['name'], $default_ext);
        $upload->generate_filename();
        return $upload;
    }

    public static function from_stored_filename($filename)
    {
        $file = new static();
        $file->__moved = true;
        $file->filename = $filename;
        $file->source = $filename;
        $file->extension = static::parse_extension($filename);
        return $file;
    }

    public static function from_db($id)
    {
        $row = static::load_data_from_db($id);
        if (!$row) return false;
        $filename = $row->filename;
        $sf = static::from_stored_filename($filename);
        $sf->id = (int) $id;
        return $sf;
    }

    public static function load_data_from_db($id)
    {
        $ci =& get_instance();
        $data = array('id' => $id);
        $result = $ci->db->get_where('nr_stored_file', $data);
        return $result->row();
    }

    public static function url_from_filename($filename)
    {
        $ci =& get_instance();
        $prefix = $ci->conf('files_url');
        return build_url($prefix, $filename);
    }

    public static function file_from_filename($filename)
    {
        return static::resolve_file($filename);
    }

    public static function resolve_file($filename, $is_create = false)
    {
        $ci =& get_instance();
        $storage = $ci->conf('files_dir_storage');
        $level1  = $ci->conf('files_dir_level1');
        $storage_file = build_path($storage, $filename);
        $level1_file  = build_path($level1, $filename);

        // doesn't matter if file exists as we are creating it
        if ($is_create) return build_path($level1, $filename);

        // level 1 exists, use that
        if (is_file($level1_file))
            return $level1_file;

        // fallback to storage (if exists)
        if (is_file($storage_file))
            return $storage_file;

        // file missing? give the level1
        // filename so that it can be created
        // if the calling code can handle it
        return $level1_file;
    }

}