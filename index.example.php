<?php

/**
 * This is an example file for initialising sitecmd.
 *
 * In an ideal world, you would keep this outside your web directory if you
 * are storing sensitive information (such as database passwords) in this file.
 *
 * If you're not, you can either put this file in your web directory, or
 * include it from a file there.
 */

// The absolute path to your environment.php file
define('SITECMD_ENV_FILE', '');

// The absolute path to 'sitecmd.php'
require '';

// Run sitecmd and return the file contents
$content = sitecmd::init();

/**
 * Everything below is optional, but is included here to save you time if you
 * want a templated website.
 *
 * The following creates two new sitecmd attributes named 'page.title' and
 * 'page.template'. To set these, just specify them in the content file that
 * is being requested through the URL.
 *
 * If 'page.template' is NULL, the file content will be output directly to the
 * browser. This is useful for things such as AJAX responses where you want to
 * avoid sending HTML, etc.
 *
 * If 'page.template' is not specified or left empty, a file named 'default.php'
 * will be used as the template. Obviously you need to create this file first!
 */

// Always useful to set
fTimestamp::setDefaultTimezone('Europe/London');

// The absolute path to your template directory (no trailing slash)
$template_dir = '';

// Create a template object
$template = new fTemplating($template_dir);

/**
 * Set the page title.
 *
 * If you want to use this, ensure you echo $template->get('title') in the
 * appropriate place in your template. You will also need to use
 * sitecmd::set('page.title', 'Page Name') within the requested file. If
 * left empty, you'll just see the site name in the browser title bar.
 *
 * You will also need to replace MY-WEBSITE below to whatever your website is
 * named. The page title will then be displayed as "Page Name | My Site" in
 * the browser title bar.
 */
$template->set('title', (sitecmd::get('page.title') ?
	sitecmd::get('page.title').' | ' : '').'MY-WEBSITE');

$template_file = sitecmd::get('page.template');

// Sometimes we might not want a template, for example AJAX responses
if ($template_file === NULL)
{
    echo $content;
}
else
{
    include $template_dir.'/'.($template_file ?
		$template_file : 'default') .'.php';
}