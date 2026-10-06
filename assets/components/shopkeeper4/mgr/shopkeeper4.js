/* Shopkeeper 4 manager · rwolfin · GPL-3.0-only. Uses the ExtJS supplied by MODX. */
Ext.ns('Shopkeeper4');
(function (S) {
    'use strict';
    var cfg = window.Shopkeeper4Config;
    var esc = function (value) { return Ext.util.Format.htmlEncode(String(value === null || value === undefined ? '' : value)); };
    var money = function (value) { return Number(value || 0).toFixed(2); };
    var settings, settingsVersion, root;
    function rpc(task, params, done, target) {
        if (target && target.getEl()) target.getEl().mask('Загрузка…');
        Ext.Ajax.request({url:cfg.connector, method:'POST', params:Ext.apply({action:'manage', task:task, HTTP_MODAUTH:MODx.siteId}, params || {}),
            callback:function () { if (target && target.getEl()) target.getEl().unmask(); },
            success:function (response) {
                try {
                    var result = Ext.decode(response.responseText);
                    if (!result.success) { Ext.Msg.alert('Shopkeeper 4', esc(result.message)); return; }
                    if (result.object && result.object.mail_errors && result.object.mail_errors.length) Ext.Msg.alert('Заказ сохранён', 'Некоторые письма не отправлены. Откройте вкладку «История и письма» для повторной отправки.');
                    if (done) done(result.object, result);
                } catch (error) { Ext.Msg.alert('Shopkeeper 4', 'Не удалось прочитать ответ сервера: ' + esc(error.message)); }
            }, failure:function (response) { Ext.Msg.alert('Shopkeeper 4', 'Ошибка HTTP ' + response.status); }
        });
    }
    function arrayStore(rows, fields) { return new Ext.data.JsonStore({data:rows || [], fields:fields}); }
    function combo(rows, valueField, labelField, config) {
        return new Ext.form.ComboBox(Ext.apply({store:arrayStore(rows,[valueField,labelField]), valueField:valueField, displayField:labelField, mode:'local', triggerAction:'all', editable:false, forceSelection:true}, config || {}));
    }
    function selectedVersions(grid) {
        var versions={};grid.getSelectionModel().getSelections().forEach(function (r) { versions[r.get('id')]=r.get('version'); });
        return versions;
    }
    function statusCell(value) {
        var found=null;settings.statuses.forEach(function (row) { if (String(row.id)===String(value)) found=row; });
        if (!found) return esc(value);
        return '<span class="sk4-status" style="background:' + (/^#[a-f0-9]{6}$/i.test(found.color) ? found.color : '#eeeeee') + '">' + esc(found.label) + '</span>';
    }
    function dateField() { return new Ext.form.DateField({format:'d.m.Y', width:105, emptyText:'дд.мм.гггг'}); }
    function dateValue(field) { var value=field.getValue();return value && value.format ? value.format('Y-m-d') : ''; }
    function presets(from,to,apply) {
        function set(kind) {
            var now=new Date(), start=new Date(now.getFullYear(),now.getMonth(),now.getDate()),end=new Date(start);
            if (kind==='yesterday') { start.setDate(start.getDate()-1);end=new Date(start); }
            if (kind==='week') start.setDate(start.getDate()-6);
            if (kind==='month') start.setDate(start.getDate()-29);
            if (kind==='current') start.setDate(1);
            if (kind==='all') {from.setValue('');to.setValue('');} else {from.setValue(start);to.setValue(end);}
            apply();
        }
        return {text:'Период',menu:[['Сегодня','today'],['Вчера','yesterday'],['Последние 7 дней','week'],['Последние 30 дней','month'],['Этот месяц','current'],['За всё время','all']].map(function (item) {return {text:item[0],handler:function () {set(item[1]);}};})};
    }
    function createOrders() {
        var from=dateField(),to=dateField(),search=new Ext.form.TextField({width:210,emptyText:'№, email, имя или телефон'}),statuses={};
        var store=new Ext.data.JsonStore({url:cfg.connector,root:'results',totalProperty:'total',idProperty:'id',remoteSort:true,
            baseParams:{action:'manage',task:'orders',HTTP_MODAUTH:MODx.siteId},
            fields:['id','status_id','created_at','total','quantity','email','customer_id','currency','delivery_name','payment_name','tracking','context_key','version']});
        store.on('exception',function (proxy,type,action,options,response) { Ext.Msg.alert('Заказы','Не удалось загрузить список заказов.'); });
        var sm=new Ext.grid.CheckboxSelectionModel();
        var columns=[sm];
        settings.columns.forEach(function (column) {
            columns.push({header:esc(column.label),dataIndex:column.name,hidden:!column.visible,sortable:['id','created_at','total','status_id','email','customer_id'].indexOf(column.name)>=0,width:column.name==='id'?65:140,
                renderer:column.name==='status_id'?statusCell:(column.name==='total'?function(v,m,r){return money(v)+' '+esc(r.get('currency'));}:esc)});
        });
        function filters() {return {date_from:dateValue(from),date_to:dateValue(to),query:search.getValue(),statuses:Object.keys(statuses).filter(function(k){return statuses[k];}).join(',')};}
        function load() {if(!from.isValid() || !to.isValid()) return;Ext.apply(store.baseParams,filters());store.load({params:{start:0,limit:25}});}
        function openSelected() {var r=sm.getSelected();if(r) openOrder(r.get('id'),load);else Ext.Msg.alert('Заказы','Выберите заказ в таблице.');}
        var grid=new Ext.grid.GridPanel({title:'Заказы',store:store,sm:sm,columns:columns,autoHeight:true,stripeRows:true,viewConfig:{forceFit:true,emptyText:'Заказы не найдены',deferEmptyText:false},
            tbar:[{text:'Открыть заказ',handler:openSelected},'-',{text:'Изменить статус',menu:settings.statuses.map(function (status) {return {text:esc(status.label),handler:function () {
                var versions=selectedVersions(grid);if(!Object.keys(versions).length) return Ext.Msg.alert('Заказы','Выберите заказы.');
                Ext.Msg.confirm('Изменить статус','Изменить статус выбранных заказов на «'+esc(status.label)+'»?',function(btn){if(btn==='yes') rpc('status',{payload:Ext.encode({versions:versions,status_id:status.id})},load,grid);});
            }};})},{text:'Удалить',handler:function () {
                var versions=selectedVersions(grid);if(!Object.keys(versions).length) return Ext.Msg.alert('Заказы','Выберите заказы.');
                Ext.Msg.confirm('Удаление заказов','Убрать выбранные заказы из списка и вернуть зарезервированные остатки? Записи сохранятся в БД как архивные.',function(btn){if(btn==='yes') rpc('remove',{payload:Ext.encode({versions:versions})},load,grid);});
            }},'->',{text:'Экспорт CSV',handler:function () {rpc('export',filters(),function (result) {window.location.href=result.url;},grid);}},{text:'Обновить',handler:load}],
            bbar:new Ext.PagingToolbar({store:store,pageSize:25,displayInfo:true}),
            listeners:{rowdblclick:function(g,index){openOrder(store.getAt(index).get('id'),load);},render:function(g){
                var toolbar=new Ext.Toolbar({items:[presets(from,to,load),'От',from,'До',to,{text:'Статусы',menu:settings.statuses.map(function(s){return {text:esc(s.label),checked:false,checkHandler:function(item,checked){statuses[s.id]=checked;}};})},search,{text:'Применить',handler:load}]});
                toolbar.render(g.getTopToolbar().getEl().parent());
                search.on('specialkey',function(field,event){if(event.getKey()===event.ENTER) load();});load();
            }}
        });
        return grid;
    }
    function optionsEditor(record,refresh) {
        var data=record.get('options') || [];
        var grid=editableGrid(data,[{name:'name',label:'Ключ TV'},{name:'id',label:'ID варианта'},{name:'label',label:'Параметр'},{name:'value',label:'Значение'},{name:'price',label:'Доплата',type:'number',precision:2}],{name:'',id:'',label:'',value:'',price:'0.00'},260);
        var win=new Ext.Window({title:'Параметры товара',width:650,modal:true,layout:'fit',items:grid,buttons:[{text:'Применить',handler:function(){grid.stopEditing();record.set('options',gridValues(grid));refresh();win.close();}},{text:'Закрыть',handler:function(){win.close();}}]});win.show();
    }
    function openOrder(id,refreshList) {
        rpc('order',{id:id},function (order) {
            var itemStore=arrayStore(order.items,['id','product_id','name','quantity','price','options','total','provider','stock_key','reserved']);
            var status=combo(settings.statuses,'id','label',{fieldLabel:'Статус',value:Number(order.status_id),anchor:'100%'});
            var delivery=combo(settings.delivery,'id','label',{fieldLabel:'Доставка',value:Number(order.delivery_id),anchor:'100%'});
            var payment=combo(settings.payments,'id','label',{fieldLabel:'Оплата',value:Number(order.payment_id),anchor:'100%'});
            var fee=new Ext.form.NumberField({fieldLabel:'Цена доставки',value:order.delivery_fee,minValue:0,decimalPrecision:2,anchor:'100%'});
            var tracking=new Ext.form.TextField({fieldLabel:'Трек-номер',value:order.tracking,anchor:'100%',maxLength:255});
            var note=new Ext.form.TextArea({fieldLabel:'Заметка менеджера',value:order.note,anchor:'100%',height:50});
            var total=new Ext.form.DisplayField({value:'',cls:'sk4-total'});
            function sum() {
                var amount=0,count=0;itemStore.each(function(r){var price=Number(r.get('price')||0),qty=Number(r.get('quantity')||0);(r.get('options')||[]).forEach(function(o){price+=Number(o.price||0);});amount+=Math.round(price*qty*100)/100;count+=qty;});
                total.setValue('Позиций: '+itemStore.getCount()+' · Количество: '+count.toFixed(3)+' · Итого: '+money(amount+Number(fee.getValue()||0))+' '+esc(order.currency));
            }
            var sm=new Ext.grid.RowSelectionModel({singleSelect:true});
            var items=new Ext.grid.EditorGridPanel({title:'Состав заказа',height:280,store:itemStore,sm:sm,clicksToEdit:1,stripeRows:true,viewConfig:{forceFit:true},
                columns:[{header:'ID товара',dataIndex:'product_id',width:75,editor:new Ext.form.NumberField({minValue:0,allowDecimals:false}),renderer:esc},{header:'Товар',dataIndex:'name',width:220,editor:new Ext.form.TextField({allowBlank:false,maxLength:255}),renderer:esc},{header:'Количество',dataIndex:'quantity',width:90,editor:new Ext.form.NumberField({minValue:.001,decimalPrecision:3}),renderer:esc},{header:'Цена',dataIndex:'price',width:100,editor:new Ext.form.NumberField({minValue:0,decimalPrecision:2}),renderer:money},{header:'Параметры',dataIndex:'options',width:200,renderer:function(opts){return (opts||[]).map(function(o){return esc(o.label||o.name)+': '+esc(o.value)+' (+'+money(o.price)+')';}).join('<br>');}}],
                tbar:[{text:'Добавить позицию',handler:function(){items.stopEditing();var r=new itemStore.recordType({id:0,product_id:0,name:'',quantity:'1.000',price:'0.00',options:[]});itemStore.add(r);sm.selectRecords([r]);items.startEditing(itemStore.getCount()-1,1);sum();}},{text:'Параметры',handler:function(){items.stopEditing();var r=sm.getSelected();if(r) optionsEditor(r,function(){items.getView().refresh();sum();});}},{text:'Убрать позицию',handler:function(){var r=sm.getSelected();if(r){itemStore.remove(r);sum();}}}],listeners:{afteredit:sum,rowdblclick:function(g,index){optionsEditor(itemStore.getAt(index),sum);}}});
            var contactFields={};var contactItems=[];
            settings.contacts.forEach(function(f){var field=f.type==='textarea'?new Ext.form.TextArea({height:70}):new Ext.form.TextField({vtype:f.type==='email'?'email':undefined});Ext.apply(field,{fieldLabel:esc(f.label),allowBlank:!f.required,anchor:'100%',maxLength:4000});field.setValue(order.contacts[f.name]||'');contactFields[f.name]=field;contactItems.push(field);});
            var history=new Ext.grid.GridPanel({title:'История изменений',height:230,store:arrayStore(order.history,['created_at','actor_id','message']),columns:[{header:'Дата UTC',dataIndex:'created_at',width:160,renderer:esc},{header:'Пользователь',dataIndex:'actor_id',width:90,renderer:esc},{header:'Изменение',dataIndex:'message',width:450,renderer:esc}],viewConfig:{forceFit:true}});
            var mailStore=arrayStore(order.mail,['id','recipient','attempts','sent_at','error','created_at']);var mailSm=new Ext.grid.RowSelectionModel({singleSelect:true});
            var mail=new Ext.grid.GridPanel({title:'Письма',height:200,store:mailStore,sm:mailSm,columns:[{header:'Кому',dataIndex:'recipient',width:220,renderer:esc},{header:'Отправлено UTC',dataIndex:'sent_at',width:150,renderer:esc},{header:'Попыток',dataIndex:'attempts',width:65,renderer:esc},{header:'Ошибка',dataIndex:'error',width:300,renderer:esc}],viewConfig:{forceFit:true},tbar:[{text:'Повторить отправку',handler:function(){var r=mailSm.getSelected();if(r && !r.get('sent_at')) rpc('mail/retry',{id:r.get('id')},function(){win.close();openOrder(id,refreshList);},mail);}}]});
            var info=new Ext.form.FormPanel({title:'Данные заказа',bodyStyle:'padding:16px',labelWidth:145,border:false,items:[{xtype:'displayfield',value:'Создан: '+esc(order.created_at)+' UTC · Контекст: '+esc(order.context_key)+' · Пользователь: '+esc(order.customer_id)},status,delivery,fee,payment,tracking,note,items,total]});
            var contacts=new Ext.form.FormPanel({title:'Контактная информация',bodyStyle:'padding:20px',labelWidth:160,border:false,items:contactItems});
            var win=new Ext.Window({title:'Заказ №'+id,width:Math.min(1100,Ext.getBody().getViewSize().width-50),height:Math.min(800,Ext.getBody().getViewSize().height-40),modal:true,maximizable:true,layout:'fit',items:{xtype:'tabpanel',activeTab:0,deferredRender:false,defaults:{autoScroll:true},items:[info,contacts,{title:'История и письма',bodyStyle:'padding:12px',items:[history,mail]}]},buttons:[
                {text:'Предпросмотр',handler:function(){items.stopEditing();var html='<h2>Заказ №'+id+'</h2><table class="sk4-preview"><tr><th>Товар</th><th>Кол-во</th><th>Цена</th></tr>';itemStore.each(function(r){html+='<tr><td>'+esc(r.get('name'))+'</td><td>'+esc(r.get('quantity'))+'</td><td>'+money(r.get('price'))+'</td></tr>';});html+='</table><p>'+total.getValue()+'</p>';new Ext.Window({title:'Просмотр заказа',width:650,height:440,modal:true,autoScroll:true,bodyStyle:'padding:20px',html:html}).show();}},
                {text:'Сохранить',cls:'primary-button',handler:function(){items.stopEditing();if(!info.getForm().isValid() || !contacts.getForm().isValid()) {Ext.Msg.alert('Заказ','Проверьте поля заказа и контактной информации.');return;}var values={};Object.keys(contactFields).forEach(function(k){values[k]=contactFields[k].getValue();});rpc('order/save',{payload:Ext.encode({id:id,version:order.version,status_id:status.getValue(),delivery_id:delivery.getValue(),delivery_fee:fee.getValue(),payment_id:payment.getValue(),tracking:tracking.getValue(),note:note.getValue(),contacts:values,items:itemStore.getRange().map(function(r){return r.data;})})},function(){win.close();refreshList();},win);}},
                {text:'Закрыть',handler:function(){win.close();}}
            ]});
            fee.on('change',sum);delivery.on('select',function(combo,record){var d;settings.delivery.forEach(function(row){if(row.id===record.get('id')) d=row;});if(d && order.currency===settings.general.base_currency){fee.setValue(d.price);sum();}});
            win.show();sum();
        });
    }
    function gridValues(grid) {return grid.getStore().getRange().map(function(record){return Ext.apply({},record.data);});}
    function editableGrid(rows,columns,defaults,height) {
        var store=arrayStore(rows,columns.map(function(c){return c.name;})),sm=new Ext.grid.RowSelectionModel({singleSelect:true});
        var cm=columns.map(function(c){
            var editor=c.type==='number'?new Ext.form.NumberField({minValue:0,decimalPrecision:c.precision||4}):(c.type==='bool'?new Ext.form.Checkbox():(c.values?combo(c.values.map(function(v){return {v:v};}),'v','v'):new Ext.form.TextField()));
            return {header:esc(c.label),dataIndex:c.name,width:c.width||120,editor:editor,renderer:c.type==='bool'?function(v){return v?'Да':'Нет';}:(c.name==='color'?function(v){return /^#[a-f0-9]{6}$/i.test(v)?'<span class="sk4-color" style="background:'+v+'"></span> '+esc(v):esc(v);}:esc)};
        });
        var grid=new Ext.grid.EditorGridPanel({height:height||340,store:store,sm:sm,columns:cm,clicksToEdit:1,stripeRows:true,viewConfig:{forceFit:true},
            tbar:[{text:'Добавить',handler:function(){grid.stopEditing();var data=Ext.apply({},defaults);if(Object.prototype.hasOwnProperty.call(data,'id')){var max=0;store.each(function(r){max=Math.max(max,Number(r.get('id')));});data.id=max+1;}var record=new store.recordType(data);store.add(record);sm.selectRecords([record]);grid.startEditing(store.getCount()-1,columns[0].name==='id'?1:0);}},{text:'Удалить',handler:function(){grid.stopEditing();var record=sm.getSelected();if(record) store.remove(record);}},{text:'Выше',handler:function(){var r=sm.getSelected(),i=store.indexOf(r);if(i>0){store.remove(r);store.insert(i-1,r);sm.selectRecords([r]);}}},{text:'Ниже',handler:function(){var r=sm.getSelected(),i=store.indexOf(r);if(r && i<store.getCount()-1){store.remove(r);store.insert(i+1,r);sm.selectRecords([r]);}}}]
        });
        if(columns.some(function(c){return c.name==='color';})) grid.getTopToolbar().add({text:'Выбрать цвет',handler:function(){var record=sm.getSelected();if(!record) return;new Ext.menu.ColorMenu({value:String(record.get('color')||'').replace('#',''),handler:function(menu,color){record.set('color','#'+color);}}).showAt(grid.getEl().getXY());}});
        return grid;
    }
    function createSettings() {
        var definitions={
            statuses:{title:'Статусы',columns:[{name:'id',label:'ID',type:'number'},{name:'label',label:'Название'},{name:'color',label:'Цвет'},{name:'template',label:'Чанк письма'},{name:'cancelled',label:'Возврат остатков',type:'bool'}],defaults:{id:0,label:'Новый статус',color:'#D9E9FF',template:'',cancelled:false}},
            currencies:{title:'Валюты',columns:[{name:'code',label:'Код'},{name:'label',label:'Название'},{name:'rate',label:'Курс к общей единице',type:'number'}],defaults:{code:'USD',label:'USD',rate:'1.0000'}},
            delivery:{title:'Доставка',columns:[{name:'id',label:'ID',type:'number'},{name:'label',label:'Название'},{name:'price',label:'Цена',type:'number',precision:2},{name:'free_from',label:'Бесплатно от',type:'number',precision:2},{name:'active',label:'Активен',type:'bool'}],defaults:{id:0,label:'Доставка',price:'0.00',free_from:'0.00',active:true}},
            payments:{title:'Оплата',columns:[{name:'id',label:'ID',type:'number'},{name:'label',label:'Название'},{name:'active',label:'Активен',type:'bool'}],defaults:{id:0,label:'Способ оплаты',active:true}},
            contacts:{title:'Контакты',columns:[{name:'name',label:'Имя поля'},{name:'label',label:'Подпись'},{name:'type',label:'Тип',values:['text','email','textarea']},{name:'required',label:'Обязательное',type:'bool'}],defaults:{name:'field',label:'Новое поле',type:'text',required:false}},
            columns:{title:'Столбцы заказов',columns:[{name:'name',label:'Поле',values:['id','status_id','created_at','total','quantity','email','customer_id','currency','delivery_name','payment_name','tracking','context_key']},{name:'label',label:'Заголовок'},{name:'visible',label:'Виден',type:'bool'}],defaults:{name:'tracking',label:'Трек-номер',visible:true}}
        };
        var grids={},tabs=[];
        Object.keys(definitions).forEach(function(key){var def=definitions[key];var grid=editableGrid(settings[key],def.columns,def.defaults,390);grids[key]=grid;tabs.push({title:def.title,layout:'fit',items:grid});});
        var generalFields=[['price_tv','TV цены'],['inventory_tv','TV остатков'],['options_tv','TV параметров JSON'],['base_currency','Основная валюта'],['first_status','ID первого статуса'],['manager_email','Email менеджера'],['manager_template','Чанк письма менеджеру'],['order_page','ID страницы заказа']].map(function(pair){return {xtype:'textfield',name:pair[0],fieldLabel:pair[1],value:settings.general[pair[0]],anchor:'95%'};});
        generalFields.push({xtype:'checkbox',name:'track_inventory',fieldLabel:'Учитывать остатки',checked:settings.general.track_inventory},{xtype:'checkbox',name:'fractional_quantity',fieldLabel:'Дробное количество',checked:settings.general.fractional_quantity});
        var form=new Ext.form.FormPanel({title:'Общие',bodyStyle:'padding:20px',labelWidth:190,items:generalFields});tabs.unshift(form);
        var panel=new Ext.Panel({title:'Настройки',layout:'fit',height:540,tbar:[{text:'Сохранить настройки',cls:'primary-button',handler:function(){var data={general:form.getForm().getValues()};data.general.track_inventory=form.getForm().findField('track_inventory').getValue();data.general.fractional_quantity=form.getForm().findField('fractional_quantity').getValue();Object.keys(grids).forEach(function(key){grids[key].stopEditing();data[key]=gridValues(grids[key]);});rpc('settings/save',{payload:Ext.encode({version:settingsVersion,data:data})},function(result){settings=result.data;settingsVersion=result.version;Ext.Msg.alert('Настройки','Сохранено. Перезагрузите страницу, чтобы применить новые статусы и столбцы в таблице заказов.');},panel);}},'->',{xtype:'tbtext',text:'Shopkeeper 4 · rwolfin'}],items:{xtype:'tabpanel',activeTab:0,deferredRender:false,items:tabs}});
        return panel;
    }
    function chart(rows,field) {
        var months={},series={};
        rows.forEach(function(row){months[row.month]=true;var key=String(row.status_id)+(field==='amount'?' / '+row.currency:'');series[key]=series[key]||{};series[key][row.month]=(series[key][row.month]||0)+Number(row[field]||0)/(field==='amount'?100:1);});
        var dates=Object.keys(months).sort(),keys=Object.keys(series),max=1;
        keys.forEach(function(key){dates.forEach(function(date){max=Math.max(max,series[key][date]||0);});});
        if(!dates.length) return '<p class="sk4-empty">За выбранный период заказов нет.</p>';
        var width=1000,height=310,left=65,top=25,bottom=55,right=25,inner=width-left-right;
        var html='<svg role="img" aria-label="Статистика заказов по месяцам" viewBox="0 0 '+width+' '+height+'" class="sk4-chart">';
        for(var step=0;step<=4;step++){var y=top+(height-top-bottom)*step/4;html+='<line x1="'+left+'" y1="'+y+'" x2="'+(width-right)+'" y2="'+y+'" stroke="#e3e7ec"/><text x="'+(left-10)+'" y="'+(y+4)+'" text-anchor="end">'+Math.round(max*(1-step/4)*100)/100+'</text>';}
        dates.forEach(function(date,i){var x=left+(dates.length===1?inner/2:inner*i/(dates.length-1));if(dates.length<18 || i%Math.ceil(dates.length/12)===0) html+='<text x="'+x+'" y="'+(height-23)+'" text-anchor="middle">'+esc(date)+'</text>';});
        var legend='';
        keys.forEach(function(key,index){var id=key.split(' / ')[0],status=null;settings.statuses.forEach(function(s){if(String(s.id)===id) status=s;});var palette=['#286bb9','#ba7d0e','#8a52b0','#24895d','#c34747','#278a91'];var color=palette[index%palette.length];var label=(status?status.label:id)+(field==='amount'?' / '+key.split(' / ')[1]:'');var points=[];
            dates.forEach(function(date,i){var value=series[key][date]||0;var x=left+(dates.length===1?inner/2:inner*i/(dates.length-1)),y=top+(height-top-bottom)*(1-value/max);points.push(x+','+y);html+='<circle cx="'+x+'" cy="'+y+'" r="4" fill="'+color+'"><title>'+esc(label)+' · '+esc(date)+': '+value+'</title></circle>';});
            html+='<polyline fill="none" stroke="'+color+'" stroke-width="2" points="'+points.join(' ')+'"/>';legend+='<span class="sk4-legend"><i style="background:'+color+'"></i>'+esc(label)+'</span>';
        });
        return html+'</svg><div>'+legend+'</div>';
    }
    function createStats() {
        var from=dateField(),to=dateField(),metric=combo([{id:'count',label:'Количество заказов'},{id:'amount',label:'Сумма по валютам'}],'id','label',{value:'count',width:180});
        var table=new Ext.grid.GridPanel({height:200,store:arrayStore([],['month','status_id','currency','count','amount']),columns:[{header:'Месяц',dataIndex:'month',width:150,renderer:esc},{header:'Статус',dataIndex:'status_id',width:190,renderer:statusCell},{header:'Валюта',dataIndex:'currency',width:100,renderer:esc},{header:'Заказов',dataIndex:'count',width:100,renderer:esc},{header:'Сумма',dataIndex:'amount',width:180,renderer:function(v){return money(Number(v)/100);}}],viewConfig:{forceFit:true}});
        var drawing=new Ext.Panel({border:false,bodyStyle:'padding:18px',html:'Выберите период и нажмите «Применить».'});
        var latest=[];
        function load(){var input={};if(dateValue(from)) input.date_from=dateValue(from);if(dateValue(to)) input.date_to=dateValue(to);rpc('stats',input,function(result){latest=result.rows;drawing.update(chart(latest,metric.getValue()));table.getStore().loadData(latest);},panel);}
        var panel=new Ext.Panel({title:'Статистика',autoHeight:true,tbar:[presets(from,to,load),'От',from,'До',to,metric,{text:'Применить',handler:load}],items:[drawing,table],listeners:{afterrender:load}});
        metric.on('select',function(){drawing.update(chart(latest,metric.getValue()));});return panel;
    }
    Ext.onReady(function(){
        rpc('settings',{},function(result){settings=result.data;settingsVersion=result.version;
            root=new MODx.Panel({renderTo:'shopkeeper4-manager',border:false,cls:'container sk4-manager',items:[{xtype:'box',autoEl:{tag:'h2',cls:'modx-page-header',html:'Shopkeeper 4 <small>Управление магазином</small>'}},{xtype:'tabpanel',activeTab:0,deferredRender:false,defaults:{border:false},items:[createOrders(),createStats(),createSettings()]}]});
        });
    });
    S.rpc=rpc;
}(Shopkeeper4));
