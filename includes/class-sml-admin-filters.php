<?php
defined( 'ABSPATH' ) || exit;

final class SML_Admin_Filters {
	private static $instance;
	public static function instance(){if(!self::$instance)self::$instance=new self();return self::$instance;}
	private function __construct(){
		add_action('restrict_manage_posts',array($this,'post_filters'),10,2);
		add_action('pre_get_posts',array($this,'filter_posts'),5);
		add_filter('manage_posts_columns',array($this,'post_columns'));
		add_filter('manage_pages_columns',array($this,'post_columns'));
		add_action('manage_posts_custom_column',array($this,'post_column'),10,2);
		add_action('manage_pages_custom_column',array($this,'post_column'),10,2);
		add_action('restrict_manage_terms',array($this,'term_filters'),10,2);
		add_filter('terms_clauses',array($this,'filter_terms'),10,3);
		add_filter('manage_edit-category_columns',array($this,'term_columns'));
		add_filter('manage_edit-post_tag_columns',array($this,'term_columns'));
		add_action('admin_init',array($this,'register_dynamic_taxonomy_columns'),50);
		add_action('admin_init',array($this,'register_dynamic_post_columns'),50);
	}
	public function post_filters($post_type,$which='top'){
		if('top'!==$which||!post_type_exists($post_type))return;
		$lang=isset($_GET['sml_admin_lang'])?sanitize_key(wp_unslash($_GET['sml_admin_lang'])):'';
		$missing=isset($_GET['sml_missing_lang'])?sanitize_key(wp_unslash($_GET['sml_missing_lang'])):'';
		echo '<select name="sml_admin_lang"><option value="">'.esc_html__('All languages','smart-multilingual').'</option>';
		foreach(SML_Languages::enabled()as$code){$cfg=SML_Languages::get($code);echo '<option value="'.esc_attr($code).'" '.selected($lang,$code,false).'>'.esc_html($cfg['native']).'</option>';}
		echo '</select><select name="sml_missing_lang"><option value="">'.esc_html__('All translation statuses','smart-multilingual').'</option>';
		$default = SML_Languages::default_code();
		foreach(SML_Languages::enabled()as$code){if($default===$code)continue;$cfg=SML_Languages::get($code);echo '<option value="'.esc_attr($code).'" '.selected($missing,$code,false).'>'.esc_html(sprintf(__('Missing %s','smart-multilingual'),$cfg['native'])).'</option>';}
		echo '</select>';
	}
	public function filter_posts($query){
		if(!is_admin()||!$query->is_main_query()||!current_user_can('edit_posts')||$query->get('sml_admin_internal'))return;
		global $pagenow;if('edit.php'!==$pagenow)return;
		$lang=isset($_GET['sml_admin_lang'])?sanitize_key(wp_unslash($_GET['sml_admin_lang'])):'';
		if($lang&&SML_Languages::is_enabled($lang)){
			$default=SML_Languages::default_code();$mq=(array)$query->get('meta_query');$mq[]=$default===$lang?array('relation'=>'OR',array('key'=>SML_Plugin::META_LANG,'value'=>$default),array('key'=>SML_Plugin::META_LANG,'compare'=>'NOT EXISTS')):array('key'=>SML_Plugin::META_LANG,'value'=>$lang);$query->set('meta_query',$mq);
		}
		$missing=isset($_GET['sml_missing_lang'])?sanitize_key(wp_unslash($_GET['sml_missing_lang'])):'';
		if($missing&&SML_Languages::is_enabled($missing)){
			$ids=get_posts(array('post_type'=>$query->get('post_type')?:'post','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true,'sml_admin_internal'=>1));$keep=array();
			$default=SML_Languages::default_code();foreach($ids as$id){$group=get_post_meta($id,SML_Plugin::META_GROUP,true);$current=get_post_meta($id,SML_Plugin::META_LANG,true)?:$default;if($current!==$default)continue;$links=$group?SML_Plugin::get_group_translations($group):array();if(empty($links[$missing]))$keep[]=$id;}
			$query->set('post__in',$keep?:array(0));
		}
	}
	public function post_columns($columns){$columns['sml_language']=__('Language','smart-multilingual');$columns['sml_translations']=__('Translations','smart-multilingual');return$columns;}
	public function post_column($column,$post_id){if('sml_language'===$column){$l=get_post_meta($post_id,SML_Plugin::META_LANG,true)?:SML_Languages::default_code();$c=SML_Languages::get($l);echo '<span class="sml-lang-badge">'.esc_html(strtoupper($l)).'</span> '.esc_html($c['native']);}elseif('sml_translations'===$column){$g=get_post_meta($post_id,SML_Plugin::META_GROUP,true);$links=$g?SML_Plugin::get_group_translations($g):array();foreach(SML_Languages::enabled()as$l){$ok=!empty($links[$l]);echo '<span class="sml-translation-dot '.($ok?'is-complete':'is-missing').'" title="'.esc_attr(SML_Languages::get($l)['native']).'">'.esc_html(strtoupper($l)).'</span> ';}}}
	public function term_filters($taxonomy,$post_type){$lang=isset($_GET['sml_admin_lang'])?sanitize_key(wp_unslash($_GET['sml_admin_lang'])):'';echo '<select name="sml_admin_lang"><option value="">'.esc_html__('All languages','smart-multilingual').'</option>';foreach(SML_Languages::enabled()as$c){echo '<option value="'.esc_attr($c).'" '.selected($lang,$c,false).'>'.esc_html(SML_Languages::get($c)['native']).'</option>';}echo '</select>';}
	public function filter_terms($clauses,$taxonomies,$args){if(!is_admin()||empty($_GET['sml_admin_lang']))return$clauses;$lang=sanitize_key(wp_unslash($_GET['sml_admin_lang']));if(!SML_Languages::is_enabled($lang))return$clauses;global$wpdb;$alias='sml_lang_meta';$clauses['join'].=" LEFT JOIN {$wpdb->termmeta} {$alias} ON t.term_id={$alias}.term_id AND {$alias}.meta_key='".esc_sql(SML_Taxonomy::META_LANG)."'";$default=SML_Languages::default_code();$condition=$default===$lang?$wpdb->prepare("({$alias}.meta_value=%s OR {$alias}.meta_value IS NULL)",$default):$wpdb->prepare("{$alias}.meta_value=%s",$lang);$clauses['where'].=" AND {$condition}";return$clauses;}

	public function register_dynamic_post_columns(){foreach(get_post_types(array('show_ui'=>true),'names')as$type){if('attachment'===$type)continue;add_filter('manage_'.$type.'_posts_columns',array($this,'post_columns'));add_action('manage_'.$type.'_posts_custom_column',array($this,'post_column'),10,2);}}
	public function register_dynamic_taxonomy_columns(){foreach(get_taxonomies(array('show_ui'=>true),'names')as$tax){add_filter('manage_edit-'.$tax.'_columns',array($this,'term_columns'));add_filter('manage_'.$tax.'_custom_column',array($this,'term_column'),10,3);}}
	public function term_columns($columns){$columns['sml_language']=__('Language','smart-multilingual');$columns['sml_translations']=__('Translations','smart-multilingual');return$columns;}
	public function term_column($content,$column,$term_id){if('sml_language'===$column){$l=get_term_meta($term_id,SML_Taxonomy::META_LANG,true)?:SML_Languages::default_code();return '<span class="sml-lang-badge">'.esc_html(strtoupper($l)).'</span> '.esc_html(SML_Languages::get($l)['native']);}if('sml_translations'===$column){$term=get_term($term_id);$g=get_term_meta($term_id,SML_Taxonomy::META_GROUP,true);$links=($term&&!is_wp_error($term)&&$g)?SML_Taxonomy::instance()->get_group_translations($g,$term->taxonomy):array();$out='';foreach(SML_Languages::enabled()as$l)$out.='<span class="sml-translation-dot '.(!empty($links[$l])?'is-complete':'is-missing').'">'.esc_html(strtoupper($l)).'</span> ';return$out;}return$content;}
}
