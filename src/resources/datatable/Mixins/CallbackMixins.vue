<script>
    export default{
        callback:({
            methods:{
                allCaps(content){
                    return content.toUpperCase();
                },
                mailTo(content){
                    return '<a class="mailTo" href="mailto:'+content+'">'+content+'</a>'
                },
            }
        })

    }
</script>