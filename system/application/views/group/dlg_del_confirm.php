<div id="dlg_del_confirm" dojoType="dijit.Dialog" title="Really delete?" style="display:none">
<table>
<tr>
  <td id="del_confirm_text"></td>
</tr>
<tr>
  <td align="center">
    <button dojoType="dijit.form.Button" type="submit">
      Yes
      <script type="dojo/connect" event="onClick">
      dijit.byId('dlg_del_confirm').sb_confirmed = true;
      </script>
    </button>
    <button dojoType="dijit.form.Button" type="button">
      No
      <script type="dojo/connect" event="onClick">
      dijit.byId('dlg_del_confirm').hide();
      </script>
    </button>
  </td>
</tr>
</table>
<script type="dojo/connect" event="onShow">
dijit.byId('dlg_del_confirm').sb_confirmed = false;
</script>
<script type="dojo/connect" event="onHide">
dojo.publish('sbgroup/dlg_del/close', [dijit.byId('dlg_del_confirm').sb_confirmed]);
</script>
</div>
