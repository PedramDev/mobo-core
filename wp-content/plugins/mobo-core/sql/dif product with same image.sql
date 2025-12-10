SELECT concat('https://mobomobo.ir',p."Url") FROM public."ProductImage" img
join "Product" p on p."ProductId" = img."ProductId" 
where img."Url" in (
SELECT "Url" FROM public."ProductImage"
group by "Url"
having count("Url") > 1
)