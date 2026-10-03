# Genera strumenti/mappa/mappa_portale.pdf (una pagina, caratteri standard) da mappa_portale.html: python3 genera_mappa.py (serve reportlab)
import re, html as H
from reportlab.lib.pagesizes import A4, landscape
from reportlab.platypus import SimpleDocTemplate, Paragraph, Table, TableStyle, Spacer
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib import colors
src=open('mappa_portale.html',encoding='utf-8').read()
body=src[src.index('<body>')+6:src.index('</body>')]
def pulisci(x):
    x=re.sub(r'<code>(.*?)</code>',r'<font color="#475569" size="7.5">\1</font>',x,flags=re.S)
    x=re.sub(r'<span class="nuovo">(.*?)</span>',r'<font backColor="#fef3c7">\1</font>',x,flags=re.S)
    return x
st=ParagraphStyle('n',fontName='Helvetica',fontSize=8.3,leading=10.2)
h2=lambda c: ParagraphStyle('h2',fontName='Helvetica-Bold',fontSize=11,leading=13,textColor=colors.HexColor(c),spaceAfter=2)
h3=ParagraphStyle('h3',fontName='Helvetica-Bold',fontSize=8.8,leading=11,spaceBefore=3)
li=ParagraphStyle('li',parent=st,leftIndent=8,bulletIndent=1)
lin=ParagraphStyle('lin',parent=li,backColor=colors.HexColor('#fef3c7'))
def blocco(m):
    c=re.search(r'--c:(#[0-9a-fA-F]{6})',m).group(1); out=[]
    for tag,attrs,inner in re.findall(r'<(h2|h3|li|div class="iter")([^>]*)>(.*?)</(?:h2|h3|li|div)>',m,re.S):
        t=pulisci(inner.strip()); nuovo='nuovo' in attrs
        if tag=='h2': out.append(Paragraph(t,h2(c)))
        elif tag=='h3': out.append(Paragraph(t,h3))
        elif tag=='li': out.append(Paragraph(t,lin if nuovo else li,bulletText='•'))
        else:
            t=re.sub(r'<span>(.*?)</span>',r'\1',t,flags=re.S); out.append(Paragraph(re.sub(r'\s+',' ',t),st))
    return c,out
mods=re.findall(r'<div class="mod"[^>]*>.*?(?=<div class="mod"|<div class="griglia"|<div class="leg"|$)',body,re.S)
doc=SimpleDocTemplate('mappa_portale.pdf',pagesize=landscape(A4),leftMargin=28,rightMargin=28,topMargin=24,bottomMargin=24,title='Mappa del portale Didattica DiBEST',author='Dipartimento DiBEST')
W=landscape(A4)[0]-56
el=[Paragraph('Didattica DiBEST – mappa del portale',ParagraphStyle('t',fontName='Helvetica-Bold',fontSize=17,leading=20,textColor=colors.HexColor('#9b0000'))),
    Paragraph(pulisci(re.search(r'<div class="sub">(.*?)</div>',body,re.S).group(1)),ParagraphStyle('s',parent=st,textColor=colors.HexColor('#64748b'))),Spacer(1,6)]
b=[blocco(m) for m in mods]
def riga(blocchi,larg):
    t=Table([[x[1] for x in blocchi]],colWidths=[W*l for l in larg])
    s=[('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),5),('RIGHTPADDING',(0,0),(-1,-1),5)]
    for i,x in enumerate(blocchi): s.append(('BOX',(i,0),(i,0),1.5,colors.HexColor(x[0])))
    t.setStyle(TableStyle(s)); return t
el.append(riga(b[0:3],[0.22,0.2,0.58])); el.append(Spacer(1,8))
el.append(riga(b[3:6],[0.2,0.22,0.58])); el.append(Spacer(1,6))
el.append(Paragraph(pulisci(re.search(r'<div class="leg">(.*?)</div>',body,re.S).group(1)),ParagraphStyle('l',parent=st,fontSize=7.5,textColor=colors.HexColor('#64748b'))))
doc.build(el)
