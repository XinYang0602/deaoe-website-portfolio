<?php
function orderedProductImages(array $images,string $kind): array {
 $order=$_POST[$kind.'_order']??null;
 if($order===null)return $images;
 if(!is_array($order)||count($order)!==count($images))throw new RuntimeException('图片顺序无效，请刷新后重试。');
 $expected=array_map('strval',array_keys($images));$sorted=$order;sort($sorted,SORT_STRING);sort($expected,SORT_STRING);
 if($sorted!==$expected)throw new RuntimeException('图片顺序无效，请刷新后重试。');
 $result=[];foreach($order as $i)$result[(int)$i]=$images[(int)$i];return $result;
}
function renderSortableImages(array $images,string $kind,string $label): void {
 echo '<fieldset><legend>'.h($label).'</legend><p class="muted">拖动“拖动排序”调整顺序，也可使用前移、后移按钮。新图片保存后可排序；保存草稿并发布后官网生效。每批最多12张，每张5 MB，本次所有图片合计不超过30 MB。</p><div class="sortable-images">';
 foreach($images as $i=>$src){
 echo '<div class="sortable-image"><input type="hidden" name="'.$kind.'_order[]" value="'.$i.'"><button type="button" class="image-drag" aria-label="拖动图片排序">⠿ 拖动排序</button><img class="admin-thumb" src="'.h($src).'" alt="'.h($label).' '.($i+1).'" draggable="false"><div><button type="button" data-move="-1">前移</button> <button type="button" data-move="1">后移</button></div><label class="checkbox"><input type="checkbox" name="remove_'.$kind.'[]" value="'.$i.'">移除此图片</label></div>';
 }
 echo '</div><label for="'.$kind.'_uploads">添加'.h($label).'（可多选）</label><input id="'.$kind.'_uploads" type="file" name="'.$kind.'_uploads[]" accept="image/jpeg,image/png,image/webp" multiple></fieldset>';
}
