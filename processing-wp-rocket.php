<?php
/*
Plugin Name: Log Processamento de cache / WP-Rocket
Description: Esse plugin tem como objetivo mostrar como está o processamento de cache do WP-Rocket
Version: 1.0.0
Author: Gustavo Henrique
Author URI: https://gustavo.g2d.com.br
Text Domain: processing-wp-rocket
Domain Path: /languages
Requires at least: 5.0
Requires PHP: 7.0
License: GPLv2 or later
License: GPL2
*/

// Evita acesso direto ao arquivo
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Sai se acessado diretamente
}

class plugin {
    public $wpdb;
    public $prefix;

    public function __construct() 
    {
        global $wpdb;

        $this->wpdb = $wpdb;
        $this->prefix = $wpdb->prefix;

        add_action('admin_menu', [$this, 'meu_plugin_adicionar_menu']);
    }

    /**
     * Adiciona página no admin
     * Para exibir o resultado do processamento de cache do WP-Rocket
     *
     * @return void
     */
    public function meu_plugin_adicionar_menu() 
    {
        add_menu_page(
            'Log Processamento de cache / WP-Rocket',  // Título da página
            'Log WP-Rocket',                    // Nome do menu
            'manage_options',                // Capacidade necessária
            'meu-plugin-resultados',         // Slug do menu
            [$this, 'show_results_page'],         // Função de callback
            'dashicons-list-view',           // Ícone do menu
            6                                // Posição no menu
        );
    }

    /**
     * Retorna todos os valores de tabelas do WP-Rocket
     *
     * @return array
     */
    public function all_data() 
    {
        $pages = [];

        //Consulta página de cache
        $wpr_rocket_cache = $this->get_results('wpr_rocket_cache');

        //Verifica se existe resultado
        if (empty($wpr_rocket_cache)) {
            return $pages;
        }

        //Loop de todas páginas em cache
        foreach ($wpr_rocket_cache as $page) {
            $pages[$page->url]['rocket_cache'] = $page;
        }

        $wpr_rucss_used_css = $this->get_results('wpr_rucss_used_css');
        foreach ($wpr_rucss_used_css as $page) {
            $type = ($page->is_mobile) ? 'mobile' : 'desktop';
            $pages[$page->url]['rucss_used_css'][$type] = $page;
        }

        $wpr_lazy_render_content = $this->get_results('wpr_lazy_render_content');
        foreach ($wpr_lazy_render_content as $page) {
            $type = ($page->is_mobile) ? 'mobile' : 'desktop';
            $pages[$page->url]['lazy_render_content'][$type] = $page;
        }

        $wpr_above_the_fold = $this->get_results('wpr_above_the_fold');
        foreach ($wpr_above_the_fold as $page) {
            $type = ($page->is_mobile) ? 'mobile' : 'desktop';
            $pages[$page->url]['above_the_fold'][$type] = $page;
        }

        return $pages;
    }

    /**
     * Consulta no banco de dados
     *
     * @param [type] $table_name
     * @return array
     */
    public function get_results($table_name) : array
    {
        // Prefixo da tabela é adicionado automaticamente
        $table_name = $this->prefix.$table_name;
        //Query
        $query = "SELECT * FROM $table_name LIMIT 10000";
        //Consulta
        $results = $this->wpdb->get_results($this->wpdb->prepare($query));

        return $results;
    }

    /**
     * Máscara de data
     * Ajuste de fuso horário do Wordpress
     *
     * @param [type] $date
     * @return void
     */
    public function format_date($date) 
    {
        return get_date_from_gmt($date);
    }

    public function show_results_page() 
    {
        //Retorna todos os valores de tabelas do WP-Rocket
        $results = $this->all_data();
    
        // HTML para exibir a tabela no formato padrão do admin
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Log Processamento de cache / WP-Rocket</h1>';
        echo '<table class="widefat fixed striped">';
        echo '<thead>
                <tr>
                    <th scope="col">URL</th>
                    
                    <th scope="col">[cache]<b class="block">status</b></th>
                    <th scope="col">[cache]<b class="block">modified</b></th>

                    <th scope="col">[css][desktop]<b class="block">status</b></th>
                    <th scope="col">[css][desktop]<b class="block">modified</b></th>
                    <th scope="col">[css][mobile]<b class="block">status</b></th>
                    <th scope="col">[css][mobile]<b class="block">modified</b></th>

                    <th scope="col">[lazy][desktop]<b class="block">status</b></th>
                    <th scope="col">[lazy][desktop]<b class="block">modified</b></th>
                    <th scope="col">[lazy][mobile]<b class="block">status</b></th>
                    <th scope="col">[lazy][mobile]<b class="block">modified</b></th>

                    <th scope="col">[above][desktop]<b class="block">status</b></th>
                    <th scope="col">[above][desktop]<b class="block">modified</b></th>
                    <th scope="col">[above][mobile]<b class="block">status</b></th>
                    <th scope="col">[above][mobile]<b class="block">modified</b></th>
                </tr>
              </thead>';
        echo '<tbody>';
    
        if (!empty($results)) {
            foreach ($results as $result) {
                //Caso não tenha a url
                if (empty($result['rocket_cache']->url)) {
                    continue;
                }

                $url_complete = $result['rocket_cache']->url;
                $new_url = explode(home_url(), $url_complete);
                $url = !empty($new_url[1]) ? $new_url[1] : '/';

                $cache_status = $result['rocket_cache']->status ?? '-';
                $cache_modified = $this->format_date($result['rocket_cache']->modified) ?? '-';

                $css_desktop_status = $result['rucss_used_css']['desktop']->status ?? '-';
                $css_desktop_modified = $this->format_date($result['rucss_used_css']['desktop']->modified) ?? '-';
                $css_mobile_status = $result['rucss_used_css']['mobile']->status ?? '-';
                $css_mobile_modified = $this->format_date($result['rucss_used_css']['mobile']->modified) ?? '-';

                $lazy_desktop_status = $result['lazy_render_content']['desktop']->status ?? '-';
                $lazy_desktop_modified = $this->format_date($result['lazy_render_content']['desktop']->modified) ?? '-';
                $lazy_mobile_status = $result['lazy_render_content']['mobile']->status ?? '-';
                $lazy_mobile_modified = $this->format_date($result['lazy_render_content']['mobile']->modified) ?? '-';

                $above_desktop_status = $result['above_the_fold']['desktop']->status ?? '-';
                $above_desktop_modified = $this->format_date($result['above_the_fold']['desktop']->modified) ?? '-';
                $above_mobile_status = $result['above_the_fold']['mobile']->status ?? '-';
                $above_mobile_modified = $this->format_date($result['above_the_fold']['mobile']->modified) ?? '-';

                echo '<tr>';
                    echo '<td><a href="'.$url_complete.'" target="_blank" class="block">'.esc_html($url).'</a></td>';
                    
                    echo '<td class="'.$cache_status.'">'.esc_html($cache_status).'</td>';
                    echo '<td>'.esc_html($cache_modified).'</td>';

                    echo '<td class="'.$css_desktop_status.'">'.esc_html($css_desktop_status).'</td>';
                    echo '<td>'.esc_html($css_desktop_modified).'</td>';
                    echo '<td class="'.$css_mobile_status.'">'.esc_html($css_mobile_status).'</td>';
                    echo '<td>'.esc_html($css_mobile_modified).'</td>';

                    echo '<td class="'.$lazy_desktop_status.'">'.esc_html($lazy_desktop_status).'</td>';
                    echo '<td>'.esc_html($lazy_desktop_modified).'</td>';
                    echo '<td class="'.$lazy_mobile_status.'">'.esc_html($lazy_mobile_status).'</td>';
                    echo '<td>'.esc_html($lazy_mobile_modified).'</td>';

                    echo '<td class="'.$above_desktop_status.'">'.esc_html($above_desktop_status).'</td>';
                    echo '<td>'.esc_html($above_desktop_modified).'</td>';
                    echo '<td class="'.$above_mobile_status.'">'.esc_html($above_mobile_status).'</td>';
                    echo '<td>'.esc_html($above_mobile_modified).'</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3">Nenhum resultado encontrado.</td></tr>';
        }
    
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '
        <style>
            .block{display:block;}
            td.failed{background-color:rgb(244 67 54 / 20%);}
            td.pending{background-color:rgb(255 153 0 / 20%);}
            td.to-submit{background-color:rgb(255 235 59 / 20%);}
            td.in-progress{background-color:rgb(63 81 181 / 20%);}
            td.completed{background-color:rgb(76 175 80 / 20%);}
        </style>
        ';

        if (!empty($_GET['teste'])) {
            echo '<pre style="background"#eee">';var_dump('pages', $results);echo '</pre>';
        }
    }
}

//
$plugin = new plugin();